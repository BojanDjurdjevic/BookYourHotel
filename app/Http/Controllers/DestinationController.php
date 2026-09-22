<?php
namespace App\Http\Controllers;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
class DestinationController extends Controller
{
    public function __invoke(Request $request) {
        $data = $request->validate(['q' => ['required','string','min:2','max:64']]);
        return response()->json(\App\Models\Hotel::publicCatalog()
            ->where('city', 'like', addcslashes(trim($data['q']), '%_\\').'%')
            ->select('city','country')->distinct()->orderBy('city')->orderBy('country')->limit(8)->get());
    }
}
