<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use App\Models\Order;
use App\Models\ProductOrder;
use App\Models\Product;

class OrdersController extends Controller
{
    /**
     * Request:
     * auth request
     * @param products list with qty
     */
    public function addProductToOrder(Request $request)
{
    $request->validate([
        'id' => 'required|exists:products,id',
        'qty' => 'required|integer|min:1',
    ]);

    $productId = $request->input('id');
    $qty = $request->input('qty');

    $userId = $request->user()->id;

    $order = Order::firstOrCreate([
        'user_id' => $userId,
    ]);

    // جلب تفاصيل المنتج لمعرفة السعر
    $product = Product::findOrFail($productId);
    $price = $product->price;

    // حساب التكلفة الإجمالية للمنتجات المضافة
    $totalCost = $price * $qty;

    // تحديث مجموع الطلب
    $order->total += $totalCost;
    $order->save();

    // إضافة المنتج للطلب
    $order->products()->attach($productId, ['quantity' => $qty]);

    return response()->json(['message' => 'Product added to the order successfully'], 200);
}



public function store(Request $request): JsonResponse
{
    // user - date - total: server
    // products : request

    // Validation 1: التحقق من وجود المنتجات في الطلب
    if (empty($request->products)) {
        return response()->json(
            [
                'message' => 'products are not exist!',
            ],
            400,
        );
    }

    // جمع إجمالي السعر
    $total = 0;
    $requestProducts = $request->products;

    // جمع معرّفات المنتجات من الطلب
    $requestProductsIds = [];
    foreach ($requestProducts as $rP) {
        $requestProductsIds[] = $rP['id'];
    }

    // جلب المنتجات من قاعدة البيانات
    $dbProducts = Product::whereIn('id', $requestProductsIds)->get();

    // Validation 2: التحقق من عدد المنتجات وتوافقها مع المنتجات في قاعدة البيانات
    if (count($requestProducts) != count($dbProducts)) {
        return response()->json(
            [
                'message' => 'Some of the products that you requested were not found!',
            ],
            400,
        );
    }

    // ربط المنتجات من الطلب بالمنتجات من قاعدة البيانات
    foreach ($requestProducts as &$rP) {
        foreach ($dbProducts as $dP) {
            if ($rP['id'] == $dP->id) {
                $rP['object'] = $dP;
            }
        }
    }

    // إنشاء الطلب
    $order = Order::create([
        'user_id' => $request->user()->id,
        'total' => 0,
        'date' => date('Y-m-d H:i:s'),
    ]);

    // إضافة المنتجات إلى الطلب وحساب الإجمالي
    foreach ($requestProducts as $rProduct) {
        $total += $rProduct['price'] * $rProduct['qty']; // استخدام السعر من الطلب بدلاً من قاعدة البيانات

        // إضافة المنتج إلى الطلب
        ProductOrder::create([
            'product' => $rProduct['id'],
            'qty' => $rProduct['qty'],
            'order' => $order->id,
        ]);
    }

    // تعيين الإجمالي وحفظ الطلب
    $order->total = $total;
    $order->save();

    return response()->json([
        'message' => 'Order has been created successfully',
        'order' => $order,
    ], 200);
}


    /**
     * Display a listing of the resource.
     */
    public function indexAdmin(Request $request): JsonResponse
    {
        // $products = ProductOrder::where('id', '>=', 0)
        //     ->with('product_object')->get();
        // return response()->json([
        //     'message' => 'Orders has been retrived successfully',
        //     'order' => $products,
        // ], 200);

        $orders = Order::with('products')->get();//where('user_id', $request->user()->id)
          //  ->

        return response()->json([
            'message' => 'Orders has been retrived successfully',
            'order' => $orders,
        ], 200);
    }
    public function indexUser(Request $request): JsonResponse
    {
        // $products = ProductOrder::where('id', '>=', 0)
        //     ->with('product_object')->get();
        // return response()->json([
        //     'message' => 'Orders has been retrived successfully',
        //     'order' => $products,
        // ], 200);

        $orders = Order::where('user_id', $request->user()->id)->with('products')->get();


        return response()->json([
            'message' => 'Orders has been retrived successfully',
            'order' => $orders,
        ], 200);
    }
    /**
     * Display the specified resource.
     */
    public function show(Order $order)
    {
        //
    }

    /**
     * Update the specified resource in storage.
     */

     public function update(Request $request, Order $order)
    {
        // get & check order
        $order = Order::where(
            [
                ['id', $request->order],
      //          ['user_id', $request->user()->id]
            ]
        )->with('products')->first();

        // check order & products
        if (empty($order) || empty($request->products)) {
            return response()->json([
                'message' => 'Order/Products were not found'
            ], 400);
        }

        // check products in request
        foreach ($request->products as $product) {
            foreach ($order->products as  $prodOrder) {
                if ($product['id'] == $prodOrder->id) {
                    // requested Product Order Record to be updated was found =>
                    // check update Type:
                    // 1) delete
                    if ($product['qty'] == -1) {
                        $prodOrder->delete();
                    }
                    // 2) update
                    else if (
                        $product['qty'] > 0
                        && $product['qty'] !=  $prodOrder->qty
                    ) {
                        $prodOrder->qty = $product['qty'];
                        $prodOrder->save();
                    }
                }
            }
        }

        // update total
        $total = 0; // init total
        $order = $order->fresh(); // sync ram & db
        // calc the new total
        foreach ($order->products as $pO) {
            $total = $total + ($pO->qty * $pO->product_object->price);
        }
        $order->total = $total;
        $order->save();


        return response()->json([
            'message' => 'Order has been updated successfully',
            'order' => $order,
        ], 200);
    }
     
public function deleteOrder(Request $request, $orderId)
{
    // get & check order
    $order = Order::where('id', $orderId)->first();

    // check order
    if (empty($order)) {
        return response()->json([
            'message' => 'Order not found'
        ], 404);
    }

    // delete order
    $order->delete();

    return response()->json([
        'message' => 'Order has been deleted successfully',
    ], 200);
}


}

/*
// // init total
// $total = $order->total;

// check products in request
foreach ($request->products as $product) {
    foreach ($order->products as $k => $prodOrder) {
    if ($product['id'] == $prodOrder->id) {
        // check update:
        // 1) delete
        if ($product['qty'] == -1) {
            // $total -= ($prodOrder->qty * $prodOrder->product_object->price);
            $prodOrder->delete();
            // unset($order->products[$k]);
        }
    // 2) update
    else if (
        $product['qty'] > 0
        && $product['qty'] !=  $prodOrder->qty
    ) {
        // $total -= ($prodOrder->qty * $prodOrder->product_object->price);
        $prodOrder->qty = $product['qty'];
                $prodOrder->save();
                // $total += ($prodOrder->qty * $prodOrder->product_object->price);
            }
        }
    }
}
*/
