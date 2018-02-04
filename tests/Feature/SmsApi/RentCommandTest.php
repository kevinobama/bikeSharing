<?php
namespace Tests\Feature\SmsApi;

use BikeShare\Domain\Bike\Bike;
use BikeShare\Domain\Stand\Stand;
use BikeShare\Domain\User\User;
use BikeShare\Notifications\Sms\BikeAlreadyRented;
use BikeShare\Notifications\Sms\BikeDoesNotExist;
use BikeShare\Notifications\Sms\BikeNotTopOfStack;
use BikeShare\Notifications\Sms\BikeRentedSuccess;
use BikeShare\Notifications\Sms\InvalidArgumentsCommand;
use BikeShare\Notifications\Sms\RechargeCredit;
use BikeShare\Notifications\Sms\RentLimitExceeded;
use Notification;

class RentCommandTest extends BaseSmsTest
{
    /** @test */
    public function missing_bike_number_fails()
    {
        $user = create(User::class);
        Notification::fake();
        $this->sendSms($user, 'RENT');
        Notification::assertSentTo($user, InvalidArgumentsCommand::class);
    }

    /** @test */
    public function renting_non_existing_bike_number_fails()
    {
        $user = create(User::class);
        standWithBike([], ['bike_num'=>1]);

        Notification::fake();
        $this->sendSms($user, 'RENT 2');

        Notification::assertSentTo($user, BikeDoesNotExist::class);
    }

    /** @test */
    public function renting_ok()
    {
        $user = userWithResources();
        standWithBike([], ['bike_num'=>1]);

        Notification::fake();
        $this->sendSms($user, 'RENT 1');

        Notification::assertSentTo($user, BikeRentedSuccess::class);
    }

    /** @test */
    public function rent_with_low_credit_fails()
    {
        $user = create(User::class, ['credit' => $this->appConfig->getRequiredCredit() - 1, 'limit' => 1]);
        standWithBike([], ['bike_num'=>1]);

        Notification::fake();
        $this->sendSms($user, 'RENT 1');

        Notification::assertSentTo($user, RechargeCredit::class);
    }

    /** @test */
    public function rent_bike_already_rented_fails()
    {
        $user = userWithResources();
        standWithBike([], ['bike_num'=>1]);

        Notification::fake();
        $this->sendSms($user, 'RENT 1');
        $this->sendSms($user, 'RENT 1');

        Notification::assertSentTo($user, BikeAlreadyRented::class);
    }

    /** @test */
    public function rent_command_bike_not_top_of_stack()
    {
        $user = userWithResources();
        $stand = create(Stand::class);
        $stand->bikes()->save(make(Bike::class, ['bike_num' => 1, 'stack_position'=>0]));
        $stand->bikes()->save(make(Bike::class, ['bike_num' => 2, 'stack_position'=>1]));
        config(['bike-share.stack_bike' => true]);

        Notification::fake();
        $this->sendSms($user, 'RENT 1');

        Notification::assertSentTo($user, BikeNotTopOfStack::class);
    }

    /** @test */
    public function rent_command_max_number_of_rents_exceeded()
    {
        $user = create(User::class, ['credit' => $this->appConfig->getRequiredCredit(), 'limit' => 0]);
        standWithBike([], ['bike_num'=>1]);

        Notification::fake();
        $this->sendSms($user, 'RENT 1');

        Notification::assertSentTo($user, RentLimitExceeded::class);
    }
}
