<?php

namespace App\Http\Controllers\backend;

use App\Http\Controllers\Controller;
use App\Models\in_characteristics_for_product;
use App\Models\in_list_characteristics;
use App\Models\in_product_list_characteristics;
use App\Models\in_product_lists;
use App\Models\in_messages;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class MoveAndChangeController extends Controller
{
    public function __construct()
    {
        $this->middleware('auth');
    }

    public function move()
    {

        $building = DB::table('buildings')->get();
        $auditories = DB::table('auditories')->get();

        $sortedAuditories = $auditories->sortBy('auditoryName');

        return view('backend.invertory.redactor.move', ['building' => $building, 'auditories' =>$sortedAuditories]);
    }

    public function editAll ($id_product)
    {
        $edit = in_product_lists::with(['characteristics' => function ($query) {
            $query->with('characteristic');
        }])
            ->leftJoin('auditories', 'in_product_lists.auditoryID', '=', 'auditories.auditoryID')
            ->leftJoin('buildings', 'in_product_lists.buildingID', '=', 'buildings.buildingID')
            ->leftJoin('in_product_name', 'in_product_lists.id_name', '=', 'in_product_name.id_name')
            ->leftJoin('tutors', 'in_product_lists.TutorID', '=', 'tutors.TutorID')
            ->select(
                'in_product_lists.*',
                'buildings.buildingName',
                'auditories.auditoryName',
                'in_product_name.name_product',
                DB::raw("CONCAT(tutors.lastname, ' ', tutors.firstname) AS tutor_fullname")
            )
            ->where('in_product_lists.actual_inventory', 1)
            ->find($id_product);
        return view('backend.invertory.redactor.move', compact('edit'));
    }

    public function getForm($id_name)
    {
        $productListCharacteristics = in_product_list_characteristics::where('id_name', $id_name)->get();

        $forms = $productListCharacteristics->map(function ($item) {
            $listCharacteristic = in_list_characteristics::where('id_characteristic', $item->id_characteristic)->first();
            return [
                'id_characteristic' => $item->id_characteristic,
                'input_characteristic' => $listCharacteristic ? $listCharacteristic->input_characteristic : ''
            ];
        });

        return response()->json(['forms' => $forms]);
    }

    public function change()
    {
        return view('backend.invertory.redactor.change');
    }

    public function updateAll(Request $request,$id_product)
    {
        DB::table('in_product_lists')->where('id_product', $id_product)->first();
        $data = array();
        $data['buildingID'] = $request->buildingID;
        $data['auditoryID'] = $request->auditoryID;
        $data['TutorID'] = $request->TutorID;
        $data['status'] = 1;
        $data['redactor_id'] = Auth::user()->TutorID;

        $update = DB::table('in_product_lists')->where('id_product', $id_product)->update($data);


        $values = $request->names;
        $id_characteristics = $request->id_characteristic;

        foreach ($values as $index => $value) {
            $id_characteristic = $id_characteristics[$index];

            // Находим соответствующую характеристику
            $characteristic = in_characteristics_for_product::where('id_product', $id_product)
                ->where('id_characteristic', $id_characteristic)
                ->first();

            // Если характеристика найдена, обновляем ее значение
            if ($characteristic) {
                $characteristic->update(['characteristic_value' => $value]);
            } else {
                // Иначе, вставляем новую запись
                in_characteristics_for_product::insert([
                    'id_product' => $id_product,
                    'id_characteristic' => $id_characteristic,
                    'characteristic_value' => $value,
                ]);
            }
        }
        return redirect()->route('all')->with('success','Инвертаризация успешна обновлена!');
    }

    public function confirmStatus($id)
    {
        // Находим запись по ID
        $item = in_product_lists::where('id_product', $id)->firstOrFail();

        $adminTutorID = [646, 359];
        // Проверяем, является ли текущий пользователь администратором
        if (in_array(Auth::user()->TutorID, $adminTutorID)) {
            // Обновляем значение поля "status"
            $item->status = 2; // Замените 2 на нужное значение для подтвержденного статуса
            $item->save();

            $actual = in_messages::where('id_product', $id)->delete();

        }

        // Перенаправляем обратно на предыдущую страницу
        return back();
    }

    public function refuseStatus(Request $request, $id)
    {
        // Находим запись по ID
        $item = in_product_lists::where('id_product', $id)->first();

        $adminTutorID = [646, 359];
        // Проверяем, является ли текущий пользователь администратором
        if (in_array(Auth::user()->TutorID, $adminTutorID)) {
            // Обновляем значение поля "status"
            $item->status = 3; // Замените 2 на нужное значение для подтвержденного статуса
            $item->save();

            // Создаем новую запись в таблице in_messages
            $message = new in_messages();
            $message->message = $request->input('message');
            $message->TutorID = $request->input('redactor_id');
            $message->inv_number = $request->input('inv_number');
            $message->id_name = $request->input('id_name');
            $message->id_product = $request->input('id_product');
            $message->save();
        }

        // Перенаправляем обратно на предыдущую страницу
        return back()->with('info', 'Отправлен на доработку');
    }

    public function change_tutor(){

        return view('backend.invertory.redactor.change');

    }

    public function search_item(Request $request)
    {
        // Получаем поисковой запрос из запроса GET
        $query = $request->input('query', '');

        // Выполняем поиск в таблице InProductList по полю inv_number
        $products = in_product_lists::with(['characteristics' => function ($query) {
            $query->with('characteristic');
        }])
            ->leftJoin('auditories', 'in_product_lists.auditoryID', '=', 'auditories.auditoryID')
            ->leftJoin('buildings', 'in_product_lists.buildingID', '=', 'buildings.buildingID')
            ->leftJoin('in_product_name', 'in_product_lists.id_name', '=', 'in_product_name.id_name')
            ->leftJoin('tutors', 'in_product_lists.TutorID', '=', 'tutors.TutorID')
            ->where('in_product_lists.inv_number', 'like', "%$query%") // Фильтруем по inv_number
            ->select(
                'in_product_lists.*',
                'buildings.buildingName',
                'auditories.auditoryName',
                'in_product_name.name_product',
                DB::raw("CONCAT(tutors.lastname, ' ', tutors.firstname) AS tutor_fullname")
            )
            ->where('in_product_lists.actual_inventory', 1)
            ->get();

        // Возвращаем результат поиска на страницу search.blade.php
        return view('backend.invertory.redactor.search')->with('product', $products)->with('query', $query);
    }

    public function editChange ($id_product)
    {
        $edit = in_product_lists::with(['characteristics' => function ($query) {
            $query->with('characteristic');
        }])
            ->leftJoin('auditories', 'in_product_lists.auditoryID', '=', 'auditories.auditoryID')
            ->leftJoin('buildings', 'in_product_lists.buildingID', '=', 'buildings.buildingID')
            ->leftJoin('in_product_name', 'in_product_lists.id_name', '=', 'in_product_name.id_name')
            ->leftJoin('tutors', 'in_product_lists.TutorID', '=', 'tutors.TutorID')
            ->select(
                'in_product_lists.*',
                'buildings.buildingName',
                'auditories.auditoryName',
                'in_product_name.name_product',
                DB::raw("CONCAT(tutors.lastname, ' ', tutors.firstname) AS tutor_fullname")
            )
            ->where('in_product_lists.actual_inventory', 1)
            ->find($id_product);
        return view('backend.invertory.redactor.edit_change', compact('edit'));
    }

    public function insert(Request $request, $id_product)
    {
        $id = DB::table('in_product_lists')->insertGetId([
            'id_name' => $request->input('id_name'),
            'buildingID' => $request->input('buildingID'),
            'auditoryID' => $request->input('auditoryID'),
            'TutorID' => $request->input('TutorID'),
            'type' => $request->input('type'),
            'inv_number' => $request->input('inv_number'),
            'status' => 1,
            'redactor_id'=> Auth::user()->TutorID,
        ]);

        in_product_lists::where('id_product', $id_product)->update(['actual_inventory' => 0]);

        $values = $request->names;
        $id_characteristics = $request->id;
        foreach ($values as $index => $value) {
            $id_characteristic = $id_characteristics[$index];

            $characteristic = in_characteristics_for_product::where('id_product', $id_product)
                ->where('id_characteristic', $id_characteristic);

            if ($characteristic) {
                $characteristic->update(['id_product' => $id]);
            } else {
                // Иначе, вставляем новую запись
                in_characteristics_for_product::insert([
                    'id_product' => $id,
                    'id_characteristic' => $id_characteristic,
                    'characteristic_value' => $value,
                ]);
            }

        }

        return redirect()->route('all')->with('success','Перемещение успешно совершено!');
    }

    public function story($id_name){

        $results = DB::table('in_product_lists')
            ->where('id_name', $id_name)
            ->join('auditories', 'in_product_lists.auditoryID', '=', 'auditories.auditoryID')
            ->join('tutors AS tutor', 'in_product_lists.TutorID', '=', 'tutor.TutorID')
            ->join('tutors AS redactor', 'in_product_lists.redactor_id', '=', 'redactor.TutorID')
            ->select(
                'auditories.auditoryName',
                DB::raw("CONCAT(tutor.lastname, ' ', tutor.firstname) AS tutor_fullname"),
                DB::raw("CONCAT(redactor.lastname, ' ', redactor.firstname) AS redactor_fullname"),
                'in_product_lists.updated_at'
            )
            ->orderBy('updated_at', 'ASC')
            ->get();

        return view('backend.invertory.redactor.story', compact('results'));
    }




}
