<?php

namespace BikeShare\Http\Controllers\Admin\Stands;

use BikeShare\Domain\Bike\BikeStatus;
use BikeShare\Domain\Stand\Requests\CreateStandRequest;
use BikeShare\Domain\Stand\Requests\UpdateStandRequest;
use BikeShare\Domain\Stand\StandsRepository;
use BikeShare\Domain\Stand\StandStatus;
use BikeShare\Http\Controllers\Controller;
use BikeShare\Http\Requests;
use Illuminate\Http\Request;

class StandsController extends Controller
{
    const STAND_MEDIA_COLLECTION = 'stand';

    protected $standRepo;

    public function __construct(StandsRepository $repository)
    {
        $this->standRepo = $repository;
    }


    /**
     * Display a listing of the resource.
     *
     * @return \Illuminate\Http\Response
     */
    public function index()
    {
        $stands = $this->standRepo->with(['bikes'])->all();

        return view('admin.stands.index', [
            'stands' => $stands,
        ]);
    }


    /**
     * Show the form for creating a new resource.
     *
     * @return \Illuminate\Http\Response
     */
    public function create()
    {
        // Bratislava Coordinates
        $defaultLongitude = '17.1071373';
        $defaultLatitude = '48.1458923';

        return view('admin.stands.create', [
            'statuses' => with(new StandStatus())->toArray(),
            'media' => collect(),
            'defaultLongitude' => $defaultLongitude,
            'defaultLatitude' => $defaultLatitude
        ]);
    }


    /**
     * Store a newly created resource in storage.
     *
     * @param CreateStandRequest|Request $request
     *
     * @return \Illuminate\Http\Response
     */
    public function store(CreateStandRequest $request)
    {
        $stand = $this->standRepo->create($request->all());

        if (isset($request->images)) {
            foreach ($request->images as $image) {
                $stand->addMedia($image)->toMediaCollection(self::STAND_MEDIA_COLLECTION);
            }
        }

        toastr()->success('Stand successfully created');

        return redirect()->route('admin.stands.index');
    }


    /**
     * Display the specified resource.
     *
     * @param  int $uuid
     *
     * @return \Illuminate\Http\Response
     */
    public function show($uuid)
    {
        $stand = $this->standRepo->findByUuid($uuid);
        $bikes = $stand->bikes()->with([
            'rents' => function ($query) {
                $query->latest()->with('user')->first();
            },
        ])->where('status', BikeStatus::FREE)->get();

        //$img = $stand->getMedia(self::STAND_MEDIA_COLLECTION)[0];

        return view('admin.stands.show', [
            'stand' => $stand,
            'bikes' => $bikes,
            'media' => $stand->getMedia(self::STAND_MEDIA_COLLECTION)
        ]);
    }


    /**
     * Show the form for editing the specified resource.
     *
     * @param  int $uuid
     *
     * @return \Illuminate\Http\Response
     */
    public function edit($uuid)
    {
        $stand = $this->standRepo->findByUuid($uuid);

        // FORMAT AND SEND STATUSESS TO FE

        return view('admin.stands.edit', [
            'stand' => $stand,
            'statuses' => with(new StandStatus())->toArray(),
            'media' => $stand->getMedia('stand')
        ]);
    }


    /**
     * Update the specified resource in storage.
     *
     * @param UpdateStandRequest|Request $request
     * @param  int                       $uuid
     *
     * @return \Illuminate\Http\Response
     */
    public function update(UpdateStandRequest $request, $uuid)
    {
        $this->standRepo->update($request->except('images'), $uuid, 'uuid');
        $stand = $this->standRepo->findByUuid($uuid);

        if ($request->images){
            foreach ($request->images as $image) {
                $stand->addMedia($image)->toMediaCollection(self::STAND_MEDIA_COLLECTION);
            }
        }

        toastr()->success('Stand successfully updated');

        return redirect()->route('admin.stands.edit', $stand->uuid);
    }


    /**
     * Remove the specified resource from storage.
     *
     * @param  int $uuid
     *
     * @return \Illuminate\Http\Response
     */
    public function destroy($uuid)
    {
        $stand = $this->standRepo->findByUuid($uuid);
    }

    /**
     * Remove the specified resource from storage.
     *
     * @param  int  $mediaId
     * @return \Illuminate\Http\Response
     */
    public function destroyMedia($uuid, $mediaId)
    {
        $stand = $this->standRepo->findByUuid($uuid);
        $stand->getMedia('stand')->keyBy('id')->get($mediaId)->delete();

        return redirect()->back()->with('status', 'Media file deleted!');
    }

    public function destroyAll()
    {
        $this->blogpost->clearMediaCollection();
        return redirect()->back()->with('status', 'All media deleted!');
    }


    public function upload()
    {
        
    }
}
