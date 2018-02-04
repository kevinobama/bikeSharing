<?php

namespace BikeShare\Domain\Stand;

use BikeShare\Domain\Bike\Bike;
use BikeShare\Domain\Core\Model;
use BikeShare\Domain\Note\Note;
use Spatie\Activitylog\Traits\LogsActivity;
use Spatie\MediaLibrary\HasMedia\HasMediaTrait;
use Spatie\MediaLibrary\HasMedia\Interfaces\HasMediaConversions;
use Spatie\MediaLibrary\Media;

class Stand extends Model implements HasMediaConversions
{

    use LogsActivity, HasMediaTrait;

    public $table = 'stands';

    public $fillable = ['name', 'descriptions', 'photo', 'place_name', 'note', 'status', 'latitude', 'longitude'];

    protected static $logAttributes = [
        'name',
        'descriptions',
        'photo',
        'place_name',
        'status',
        'latitude',
        'longitude',
    ];

    public $dates = ['deleted_at'];

    public $casts = [
        'latitude' => 'decimal',
        'longitude' => 'decimal',
    ];


    public function getTopPosition()
    {
        $bike = $this->getTopBike();
        if ($bike) {
            return $bike->stack_position;
        }
        return null;
    }

    public function getTopBike()
    {
        return $this->bikes()->orderBy('stack_position', 'desc')->first();
    }

    public function bikes()
    {
        return $this->hasMany(Bike::class);
    }


    public function notes()
    {
        return $this->morphMany(Note::class, 'notable');
    }


    public function registerMediaConversions(Media $media = null)
    {
        $this->addMediaConversion('small')
            ->width(385)
            ->height(284);

        $this->addMediaConversion('thumb')
            ->width(200)
            ->height(150);
    }
}
