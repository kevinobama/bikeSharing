<?php
namespace BikeShare\Http\Controllers\Api\v1\Bikes;

use BikeShare\Domain\Bike\BikesRepository;
use BikeShare\Domain\Bike\BikeTransformer;
use BikeShare\Domain\Bike\Requests\CreateBikeRequest;
use BikeShare\Domain\Rent\MethodType;
use BikeShare\Domain\Rent\RentTransformer;
use BikeShare\Domain\Rent\Requests\RentRequest;
use BikeShare\Domain\Rent\Requests\ReturnRequest;
use BikeShare\Domain\Stand\StandsRepository;
use BikeShare\Http\Controllers\Api\v1\Controller;
use BikeShare\Http\Services\Rents\RentService;
use Illuminate\Http\Request;

class BikesController extends Controller
{

    protected $bikeRepo;

    private $rentService;

    public function __construct(BikesRepository $bikesRepository)
    {
        $this->bikeRepo = $bikesRepository;
        $this->rentService = app(RentService::class, ['methodType' => MethodType::WEB]);
    }


    /**
     * Display a listing of the resource.
     *
     * @return \Illuminate\Http\Response
     */
    public function index()
    {
        $bikes = $this->bikeRepo->all();

        return $this->response->collection($bikes, new BikeTransformer());
    }


    /**
     * Store a newly created resource in storage.
     *
     * @param CreateBikeRequest|Request $request
     *
     * @return \Illuminate\Http\Response
     */
    public function store(CreateBikeRequest $request)
    {
        $bike = $this->bikeRepo->create($request->all());

        return $this->response->item($bike, new BikeTransformer());
    }

    /**
     * Display the specified resource.
     *
     * @param $uuid
     *
     * @return \Illuminate\Http\Response
     * @internal param int $id
     *
     */
    public function show($uuid)
    {
        if (! $bike = $this->bikeRepo->findByUuid($uuid)) {
            return $this->response->errorNotFound('Bike not found');
        }

        return $this->response->item($bike, new BikeTransformer());
    }

    /**
     * Update the specified resource in storage.
     *
     * @param  \Illuminate\Http\Request $request
     * @param                           $uuid
     *
     * @return \Illuminate\Http\Response
     * @internal param int $id
     *
     */
    public function update(Request $request, $uuid)
    {
        if (! $bike = $this->bikeRepo->findByUuid($uuid)) {
            return $this->response->errorNotFound('Bike not found');
        }
        $bike->fill($request->all())->save();

        return $this->response->item($bike, new BikeTransformer());
    }


    /**
     * Remove the specified resource from storage.
     *
     * @param $uuid
     *
     * @return \Illuminate\Http\Response
     * @internal param int $id
     *
     */
    public function destroy($uuid)
    {
        if (! $bike = $this->bikeRepo->findByUuid($uuid)) {
            return $this->response->errorNotFound('Bike not found');
        }
        $bike->delete();

        return $this->response->item($bike, new BikeTransformer());
    }

    public function rentBike(RentRequest $request, $uuid)
    {
        // TODO limits, credits
        $bike = $this->bikeRepo->findByUuid($uuid);

        // TODO catch all exception types
        $rent = $this->rentService->rentBike($this->user, $bike);

        return $this->response->item($rent, new RentTransformer());
    }

    public function returnBike(ReturnRequest $request, $uuid)
    {
        $bike = $this->bikeRepo->findByUuid($uuid);

        $stand = app(StandsRepository::class)->findByUuid($request->get('stand'));

        $this->rentService->returnBike($this->user, $bike, $stand);
    }
}
