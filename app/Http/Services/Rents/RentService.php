<?php
namespace BikeShare\Http\Services\Rents;

use BikeShare\Domain\Bike\Bike;
use BikeShare\Domain\Bike\BikePermissions;
use BikeShare\Domain\Bike\BikeStatus;
use BikeShare\Domain\Bike\Events\BikeWasRented;
use BikeShare\Domain\Bike\Events\BikeWasReturned;
use BikeShare\Domain\Rent\Events\RentWasClosed;
use BikeShare\Domain\Rent\Events\RentWasCreated;
use BikeShare\Domain\Rent\Rent;
use BikeShare\Domain\Rent\MethodType;
use BikeShare\Domain\Rent\RentsRepository;
use BikeShare\Domain\Rent\RentStatus;
use BikeShare\Domain\Stand\Stand;
use BikeShare\Domain\Stand\StandPermissions;
use BikeShare\Domain\User\User;
use BikeShare\Domain\User\UsersRepository;
use BikeShare\Http\Services\AppConfig;
use BikeShare\Http\Services\Rents\Exceptions\BikeNotFreeException;
use BikeShare\Http\Services\Rents\Exceptions\BikeNotOnTopException;
use BikeShare\Http\Services\Rents\Exceptions\LowCreditException;
use BikeShare\Http\Services\Rents\Exceptions\MaxNumberOfRentsException;
use BikeShare\Http\Services\Rents\Exceptions\NotRentableStandException;
use BikeShare\Notifications\Admin\AllNotesDeleted;
use BikeShare\Notifications\Admin\BikeNoteAdded;
use BikeShare\Notifications\Admin\NotesDeleted;
use BikeShare\Notifications\Admin\StandNoteAdded;
use BikeShare\Notifications\Sms\Rent\ForceRentOverrideRent;
use BikeShare\Notifications\Sms\Ret\ForceReturnOverrideRent;
use Carbon\Carbon;
use Exception;
use Gate;
use Notification;

class RentService
{
    /**
     * @var AppConfig
     */
    private $appConfig;
    /**
     * @var RentChecks
     */
    private $rentChecks;

    /**
     * @var ReturnChecks
     */
    private $returnChecks;

    private $methodType;

    private $rentsRepo;


    public function __construct(AppConfig $appConfig, $methodType)
    {
        $this->appConfig = $appConfig;
        $this->rentChecks = new RentChecks($appConfig);
        $this->returnChecks = new ReturnChecks();
        $this->methodType = $methodType;
        $this->rentsRepo = app(RentsRepository::class);
    }


    /**
     * @param User $user
     * @param Bike $bike
     *
     * @return Rent
     */
    public function rentBike(User $user, Bike $bike)
    {
        // any failing check throws exception
        $this->rentChecks->sufficientCredit($user);
        $this->rentChecks->bikeIsFree($bike);
        $this->rentChecks->userRentLimit($user);
        $this->rentChecks->bikeTopOfStack($bike);
        $this->rentChecks->isRentableStand($bike);
        // TODO checkTooMany

        if ($this->appConfig->isStackWatchEnabled() && !$bike->isTopOfStack()){
            // TODO notifyAdmin
        }

        $oldCode = $bike->current_code;
        $standFrom = $bike->stand;

        $this->rentBikeInternal($bike, $user);
        return $this->createRentLog($user, $bike, $oldCode, $bike->current_code, $standFrom);
    }

    public function forceRentBike(User $user, Bike $bike)
    {
        Gate::forUser($user)->authorize(BikePermissions::FORCE_RENT);

        $oldCode = $bike->current_code;

        if ($bike->status == BikeStatus::OCCUPIED){
            $originalRent = $this->closeRentLogInternal(
                $this->rentsRepo->findOpenRent($bike),null, ForceCommand::rent($user)
            );

            $originalRent->user->notify(new ForceRentOverrideRent($bike));
        }

        $standFrom = $bike->stand;
        $this->rentBikeInternal($bike, $user);
        return $this->createRentLog($user, $bike, $oldCode, $bike->current_code, $standFrom, true);
    }

    private function rentBikeInternal(Bike $bike, User $user)
    {
        $bike->status = BikeStatus::OCCUPIED;
        $bike->current_code = Bike::generateBikeCode();
        $bike->stack_position = 0;
        $bike->stand()->dissociate();
        $bike->user()->associate($user);
        $bike->save();

        event(new BikeWasRented($bike, $bike->current_code, $user));
    }

    /**
     * @return Rent
     */
    private function createRentLog(User $user, Bike $bike, $oldCode, $newCode, Stand $standFrom = null, $force = false)
    {
        $rent = new Rent();
        $rent->status = RentStatus::OPEN;
        $rent->user()->associate($user);
        $rent->bike()->associate($bike);

        if ($standFrom){
            $rent->standFrom()->associate($standFrom);
        }

        if ($force){
            $rent->force_opened = true;
        }

        $rent->started_at = Carbon::now();
        $rent->old_code = $oldCode;
        $rent->new_code = $newCode;
        $rent->open_method = $this->methodType;
        $rent->save();

        event(new RentWasCreated($rent));

        return $rent;
    }

    public function closeRent(Rent $rent, Stand $standToReturn)
    {
        return $this->returnBike($rent->user, $rent->bike, $standToReturn);
    }


    /**
     * @param User   $user User initiating the command
     * @param Bike   $bike
     * @param Stand  $standTo
     *
     * @return Rent
     * @throws \BikeShare\Http\Services\Rents\Exceptions\BikeRentedByOtherUserException
     * @throws \BikeShare\Http\Services\Rents\Exceptions\BikeNotRentedException
     * @throws \BikeShare\Http\Services\Rents\Exceptions\NotReturnableStandException
     * @internal param Rent|null $rent
     */
    public function returnBike(User $user, Bike $bike, Stand $standTo)
    {
        $this->returnChecks->bikeRentedByMe($user, $bike);
        $this->returnChecks->bikeIsRented($bike);
        $this->returnChecks->isReturnableStand($standTo);

        $rent = app(RentsRepository::class)->findOpenRent($bike);

        $this->returnBikeInternal($bike, $standTo);
        $this->closeRentLogInternal($rent, $standTo);
        $this->updateCredit($rent);

        return $rent;
    }

    public function forceReturnBike(User $user, Bike $bike, Stand $standTo)
    {
        Gate::forUser($user)->authorize(BikePermissions::FORCE_RETURN);

        $rent = app(RentsRepository::class)->findOpenRent($bike);

        $this->returnBikeInternal($bike, $standTo);
        if ($rent){
            $rent->user->notify(new ForceReturnOverrideRent($bike));
            $this->closeRentLogInternal($rent, $standTo, ForceCommand::retrn($user));
        }
        return $rent;
    }


    /**
     * Return bike to old stand, no matter if user is currently renting the bike
     * Admin only
     *
     * @param User $user
     * @param Bike $bike
     *
     * @return null
     * @throws \BikeShare\Http\Services\Rents\Exceptions\BikeNotRentedException
     */
    public function revertBikeRent(User $user, Bike $bike){
        Gate::forUser($user)->authorize(BikePermissions::REVERT);

        $this->returnChecks->bikeIsRented($bike);

        $rent = app(RentsRepository::class)->findOpenRent($bike);

        $oldStand = $rent->standFrom;

        $this->returnBikeInternal($bike, $oldStand);
        $this->closeRentLogInternal($rent, $oldStand, ForceCommand::revert($user));
        return $rent;
    }

    private function returnBikeInternal(Bike $bike, Stand $standTo)
    {
        $topPosition = $standTo->getTopPosition();
        $bike->stack_position = $topPosition ? $topPosition + 1 : 0;
        $bike->status = BikeStatus::FREE;
        $bike->stand()->associate($standTo);
        $bike->user()->dissociate();
        $bike->save();

        event(new BikeWasReturned($bike, $standTo));
    }

    private function closeRentLogInternal(Rent $rent, $standTo, ForceCommand $force = null)
    {
        $rent->ended_at = Carbon::now();
        $rent->duration = $rent->ended_at->diffInSeconds($rent->started_at);
        $rent->status = RentStatus::CLOSE;
        $rent->close_method = $this->methodType;
        if ($standTo){
            $rent->standTo()->associate($standTo);
        }
        if ($force){
            $rent->force_closed = true;
            $rent->close_command = $force->forceCommand;
            $rent->closedByUser()->associate($force->user);
        }
        $rent->save();
        event(new RentWasClosed($rent));
        return $rent;
    }

    // TODO need rewrite, more modular, customizable, readable ...
    private function updateCredit(Rent $rent)
    {
        $config = $this->appConfig;
        if (! $config->isCreditEnabled()) {
            return;
        }

        $realRentCredit = 0;
        $timeDiff = $rent->started_at->diffInMinutes($rent->ended_at);

        $freeTime = $config->getFreeTime();
        $rentCredit = $config->getRentCredit();
        if ($timeDiff > $freeTime) {
            $realRentCredit += $rentCredit;
        }

        // after first paid period, i.e. free_time * 2; if price_cycle enabled
        $priceCycle = $config->getPriceCycle();
        if ($priceCycle) {
            if ($timeDiff > $freeTime * 2) {
                $tempTimeDiff = $timeDiff - ($freeTime * 2);

                if ($priceCycle == 1) {             // flat price per cycle
                    $cycles = ceil($tempTimeDiff / $config->getFlatPriceCycle());
                    $realRentCredit += ($rentCredit * $cycles);
                } elseif ($priceCycle == 2) {       // double price per cycle
                    $cycles = ceil($tempTimeDiff / $config->getDoublePriceCycle());
                    $tmpCreditRent = $rentCredit;

                    for ($i = 1; $i <= $cycles; $i++) {
                        $multiplier = $i;
                        $doublePriceCycleCap = $config->getDoublePriceCycleCap();

                        if ($multiplier > $doublePriceCycleCap) {
                            $multiplier = $doublePriceCycleCap;
                        }
                        // exception for rent=1, otherwise square won't work:
                        if ($tmpCreditRent == 1) {
                            $tmpCreditRent = 2;
                        }
                        $realRentCredit += pow($tmpCreditRent, $multiplier);
                    }
                }
            }
        }

        if ($timeDiff > $config->getWatchersLongRental() * 60) {
            $realRentCredit += $config->getCreditLongRental();
        }

        $rent->credit = $realRentCredit;
        $rent->save();
        $rent->user->credit -= $realRentCredit;
        $rent->user->save();
    }

    public function addNoteToBike(Bike $bike, User $user, $noteText)
    {
        $note = $bike->notes()->create([
            'note' => $noteText,
            'user_id' => $user->id
        ]);
        $this->notifyAdmins(new BikeNoteAdded($bike, $note, $user));
        return $note;
    }

    public function addNoteToStand(Stand $stand, User $user, $noteText)
    {
        $note = $stand->notes()->create([
            'note' => $noteText,
            'user_id' => $user->id
        ]);
        $this->notifyAdmins(new StandNoteAdded($stand, $note, $user));
        return $note;
    }

    public function deleteNoteFromBike(Bike $bike, User $user, $pattern)
    {
        Gate::forUser($user)->authorize(BikePermissions::DELETE_NOTE);
        $pattern = $pattern ? "%{$pattern}%" : "%";

        $count =  $bike->notes()
            ->where('note', 'like', $pattern)->delete();

        if ($count > 0){
            Notification::send(
                app(UsersRepository::class)->getAdmins(),
                new NotesDeleted($user, $pattern, $count, $bike)
            );
        }

        return $count;
    }

    public function deleteNoteFromStand(Stand $stand, User $user, $pattern)
    {
        Gate::forUser($user)->authorize(StandPermissions::DELETE_NOTE);
        $pattern = $pattern ? "%{$pattern}%" : "%";

        $count = $stand->notes()
            ->where('note', 'like', $pattern)->delete();

        if ($count > 0){
            $this->notifyAdmins(new NotesDeleted($user, $pattern, $count, null, $stand));
        }

        return $count;
    }

    public function deleteNoteFromAllStandBikes(Stand $stand, User $user, $pattern)
    {
        Gate::forUser($user)->authorize(StandPermissions::UNTAG);
        $pattern = $pattern ? "%{$pattern}%" : "%";

        $deleted = 0;
        foreach ($stand->bikes as $b){
            $deleted += $b->notes()->where('note', 'like', $pattern)->delete();
        }
        if ($deleted > 0){
            $this->notifyAdmins(new AllNotesDeleted($user, $pattern, $deleted, $stand));
        }
        return $deleted;
    }

    public function addNoteToAllStandBikes(Stand $stand, User $user, $noteText)
    {
        $stand->bikes->each(function($bike) use ($noteText, $user){
            $bike->notes()->create([
                'note' => $noteText,
                'user_id' => $user->id
            ]);
        });

        // TODO notify Admins (email and sms if enabled)
//        $users = app(UsersRepository::class)->getUsersWithRole('admin')->get();
//        Notification::send($users, new NoteCreated($note));
    }

    public function checkLongRent()
    {
        $rents = app(RentsRepository::class)->findWhere(['status' => RentStatus::OPEN]);

        foreach ($rents as $rent) {
            if ($rent->started_at->addHours(app(AppConfig::class)->getWatchersLongRental())->isPast()) {
                if (app(AppConfig::class)->isNotifyUser()) {
                    // TODO send notification (sms, email ?) to user about long rental
                }
            }
        }

        // TODO send notification report to admins about all long rentals
    }

    public static function checkManyRents(User $user = null)
    {
        $timeToMany = app(AppConfig::class)->getTimeToMany();
        $numberToMany = app(AppConfig::class)->getNumberToMany();
        if ($user) {
            $users = collect($user);
        } else {
            $users = app(UsersRepository::class)->findWhere([
                ['limit', '!=', 0]
            ]);
        }

        foreach ($users as $user) {
            $rents = $user->rents()->where('started_at', '>', Carbon::now()->subHour($timeToMany))->get();

            if (count($rents) >= ($user->limit + $numberToMany)) {
                // TODO prepare data for report
            }
        }

        // TODO notify admins (create report) about too many rentals of users
    }

    private function notifyAdmins($notification)
    {
        Notification::send(
            app(UsersRepository::class)->getAdmins(), $notification);
    }
}
