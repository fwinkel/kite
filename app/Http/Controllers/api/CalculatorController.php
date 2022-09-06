<?php

namespace App\Http\Controllers\api;

use App\Http\Controllers\Controller;
use App\Models\Country;
use Illuminate\Http\Request;
use App\Models\Overview;
use App\Models\ReachAgeRange;
use App\Models\ReachIncidence;
use App\Models\ReachProduct;
use App\Models\Variables;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

class CalculatorController extends Controller
{
    public $products;




    public function __construct()
    {
        $this->products = ReachProduct::all();
    }



    public function index()
    {
        $data = new \stdClass();
        $data->products = $this->products;
        $data->ageRanges = ReachAgeRange::all();
        $data->countries = Country::all();
        return response(['message' => "success", 'data' => $data], 200);
    }

    public function abstract(Request $request)
    {

        $query = $request->all();
        $allProducts = $this->products;
        $response = [];
        foreach ($allProducts as $product) {
            $response['incidencia'][str_replace(' ', '', $product->description)] = $this->calculate_incidencia($product->productCode, $query['country'], $query['gender'], $query['age']);
            $response['poblacion_proyectada'][str_replace(' ', '', $product->description)] = $this->calculate_proyectada($product->productCode, $query['country'], $query['gender'], $query['age']);
            $response['use_as_per_age'][str_replace(' ', '', $product->description)] = $this->usebyage($product->productCode, $query['country'], $query['gender']);
            $response['population_projection_by_age'][str_replace(' ', '', $product->description)] = $this->projectedPopulationbyAge($product->productCode, $query['country'], $query['gender']);
        }
        return response(['message' => "success", 'data' => $response], 200);
    }

    public function reach(Request $request)
    {
        $totalIncidence = ReachIncidence::select(DB::raw("SUM(incidence)*connectedPopulation as incidence , SUM(connectedPopulation) as connectedPopulation"))->first();
        $variables = Variables::first();
        $data = ReachIncidence::select(DB::raw("SUM(connectedPopulation) as connectedPopulation , 
        ROUND((SUM(projectedPopulation)),2) as incidence , 
        ROUND((SUM(projectedPopulation)/SUM(connectedPopulation) )*100, 2) as percentage,
        " . ROUND((($request->budget / $variables->cpm) * (1000 / $variables->frequency) / $totalIncidence->connectedPopulation) * (100), 2) . "
        as target_population
        "))
            ->when($request->country, function ($query) use ($request) {
                return $query->where('countryCode', $request->country);
            })
            ->when($request->product, function ($query) use ($request) {
                return $query->where('productCode', $request->product);
            })
            ->when($request->ageRange, function ($query) use ($request) {
                return $query->where('ageRangeCode', $request->ageRange);
            })
            ->when($request->gender, function ($query) use ($request) {
                return $query->where('gender', $request->gender);
            })
            ->get();
        return response(['message' => "success", 'data' => $data], 200);
    }

    public function getVariables()
    {
        return response(['message' => "success", 'data' => Variables::all()], 200);
    }

    public function updateVariables(Request $request, $id)
    {
        $validator = Validator::make($request->all(), [
            'frequency' => 'required|integer',
            'cpm' => 'required|integer',

        ]);

        if ($validator->fails()) {
            return response(['message' => "error", 'data' => $validator->errors()->first()], 400);
        }

        $data = Variables::find($id);
        $data->frequency = $request->frequency;
        $data->cpm = $request->cpm;
        $data->save();
        return response(['message' => "success", 'data' => $data], 200);
    }

    public function calculate_incidencia($product, $country, $gender, $age)
    {
         $countryincidences = ReachIncidence::query();
         $countryincidences->where('productCode', $product);
        if (count($country) > 0) {
            $countryincidences->whereIn('countryCode', $country);
        }
        if (count($gender) > 0) {
            $countryincidences->whereIn('gender', $gender);
        }
        if (count($age) > 0) {
            $countryincidences->whereIn('ageRangeCode', $age);
        }
        $countryresult = $countryincidences->selectRaw('sum(projectedPopulation) as projectedPopulation, sum(connectedPopulation) as connectedPopulation')->first();
        $percentage = ($countryresult->projectedPopulation / $countryresult->connectedPopulation) * 100;
        return $percentage;
    }

    public function calculate_proyectada($product, $country, $gender, $age)
    {
        $connectedpopulation = ReachIncidence::query();
        $connectedpopulation->where('productCode', $product);
        if (count($country) > 0) {
            $connectedpopulation->whereIn('countryCode', $country);
        }
        if (count($gender) > 0) {
            $connectedpopulation->whereIn('gender', $gender);
        }
        if (count($age) > 0) {
            $connectedpopulation->whereIn('ageRangeCode', $age);
        }
        $resultcp = $connectedpopulation->sum('projectedPopulation');
        return $resultcp;
    }

    public function usebyage($product, $country, $gender)
    {
        // $allproductuse = ReachIncidence::query();
        $filterproduct = ReachIncidence::query();

        // $allresult = $allproductuse->selectRaw("Count(ageRangeCode) as totalAgeRange")->first();
        $filterproduct->selectRaw("sum(projectedPopulation) as projectedPopulation, sum(connectedPopulation) as connectedPopulation, ageRangeCode")->where('productCode', $product)->with('agerange');
        if (count($country) > 0) {
            $filterproduct->whereIn('countryCode', $country);
        }
        if (count($gender) > 0) {
            $filterproduct->whereIn('gender', $gender);
        }
        $filteredpro = $filterproduct->groupBy('ageRangeCode')->get();

        $result = [];

        if (count($filteredpro) > 0) {

            foreach ($filteredpro as $index => &$value) {
                $value['percentage'] =  round(($value['projectedPopulation'] / $value['connectedPopulation']) * 100, 2);
            }
        }
        return json_encode($filteredpro);
    }

    public function projectedPopulationbyAge($product, $country, $gender)
    {
        $connectedpopulation = ReachIncidence::query();
        $connectedpopulation->where('productCode', $product);

        if (count($country) > 0) {
            $connectedpopulation->whereIn('countryCode', $country);
        }
        if (count($gender) > 0) {
            $connectedpopulation->whereIn('gender', $gender);
        }
        $result = $connectedpopulation->selectRaw("sum(projectedPopulation) as connectedpop, ageRangeCode")->with('agerange')->groupBy('ageRangeCode')->get();
        return stripslashes(json_encode($result));
    }
}
