<?php


namespace BikeShare\Http\Services\Rents;


use BikeShare\Domain\Bike\Bike;
use BikeShare\Domain\Bike\BikeStatus;
use BikeShare\Domain\Stand\StandStatus;
use BikeShare\Domain\User\User;
use BikeShare\Http\Services\AppConfig;
use BikeShare\Http\Services\Rents\Exceptions\BikeNotFreeException;
use BikeShare\Http\Services\Rents\Exceptions\BikeNotOnTopException;
use BikeShare\Http\Services\Rents\Exceptions\LowCreditException;
use BikeShare\Http\Services\Rents\Exceptions\MaxNumberOfRentsException;
use BikeShare\Http\Services\Rents\Exceptions\NotRentableStandException;
use Exception;

class RentChecks
{
    /**
     * @var AppConfig
     */
    private $appConfig;

    /**
     * RentChecks constructor.
     */
    public function __construct(AppConfig $appConfig)
    {
        $this->appConfig = $appConfig;
    }

    /**
     * @param User $user
     * @throws LowCreditException
     */
    public function sufficientCredit(User $user)
    {
        if ($this->appConfig->isCreditEnabled()){
            $requiredCredit = $this->appConfig->getRequiredCredit();

            if ($user->credit < $requiredCredit){
                throw new LowCreditException($user->credit, $requiredCredit);
            }
        }
    }

    /**
     * @param Bike $bike
     * @throws BikeNotFreeException
     */
    public function bikeIsFree(Bike $bike)
    {
        if ($bike->status != BikeStatus::FREE) {
            if (!$bike->user){
                throw new Exception("Bike not free but no owner, bike_id = {$bike->user_id}");
            }
            throw new BikeNotFreeException();
        }
    }

    /**
     * @param User $user
     * @throws MaxNumberOfRentsException
     */
    public function userRentLimit(User $user)
    {
        $currentRents = $user->bikes()->get()->count();
        if ($currentRents >= $user->limit) {
            throw new MaxNumberOfRentsException($user->limit, $currentRents);
        }
    }

    /**
     * @param Bike $bike
     * @throws BikeNotOnTopException
     */
    public function bikeTopOfStack(Bike $bike)
    {
        if ($this->appConfig->isStackBikeEnabled() && !$bike->isTopOfStack()){
            throw new BikeNotOnTopException($bike->stand->getTopBike());
        }
    }


    /**
     * @param $bike
     *
     * @throws \BikeShare\Http\Services\Rents\Exceptions\NotRentableStandException
     */
    public function isRentableStand($bike): void
    {
        if (! in_array($bike->stand->status, [StandStatus::ACTIVE, StandStatus::ACTIVE_RENT_ONLY], true)) {
            throw new NotRentableStandException($bike->stand);
        }
    }
}
