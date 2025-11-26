<?php

namespace App\Http\Controllers;

use Exception;
use Midtrans\Snap;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Product;
use App\Models\Shipment;
use App\Models\OrderItem;
use Illuminate\Http\Request;

class OrderController extends Controller
{
    public function process()
    {
        if (\Cart::isEmpty()) {
            return redirect()->route('cart.index');
        }

        \Cart::removeConditionsByType('shipping');

        $items = \Cart::getContent();
        $totalWeight = 0;

        foreach ($items as $item) {
            $totalWeight += ($item->quantity * $item->associatedModel->weight);
        }

        $provinces = $this->getProvinces();
        $cities = isset(auth()->user()->city_id) ? $this->getCities(auth()->user()->province_id) : [];

        return view('frontend.orders.checkout', compact('items', 'totalWeight', 'provinces', 'cities'));
    }

    public function cities(Request $request)
    {
        $cities = $this->getCities($request->query('province_id'));
        return response()->json(['cities' => $cities]);
    }

    public function shippingCost(Request $request)
    {
        $items = \Cart::getContent();
        $totalWeight = 0;

        foreach ($items as $item) {
            $totalWeight += ($item->quantity * $item->associatedModel->weight);
        }

        $destination = $request->input('city_id');
        return $this->getShippingCost($destination, $totalWeight);
    }

    private function getShippingCost($destination, $weight)
    {
        $results = [];

        if (!$this->rajaOngkirOrigin || !$destination || $weight <= 0) {
            \Log::error('RAJAONGKIR ERROR: Invalid config or request', [
                'origin' => $this->rajaOngkirOrigin,
                'destination' => $destination,
                'weight' => $weight,
            ]);
            return [
                'origin' => $this->rajaOngkirOrigin,
                'destination' => $destination,
                'weight' => $weight,
                'results' => [],
            ];
        }

        foreach ($this->couriers as $code => $courier) {
            $params = [
                'origin' => $this->rajaOngkirOrigin,
                'destination' => $destination,
                'weight' => $weight,
                'courier' => $code,
            ];

            \Log::info('RAJAONGKIR PARAM', $params);

            try {
                $response = $this->rajaOngkirRequest('cost', $params, 'POST');
            } catch (\Exception $e) {
                \Log::error("RAJAONGKIR [$code] request failed: " . $e->getMessage(), $params);
                continue;
            }

            if (!empty($response['rajaongkir']['results'])) {
                foreach ($response['rajaongkir']['results'] as $cost) {
                    if (!empty($cost['costs'])) {
                        foreach ($cost['costs'] as $costDetail) {
                            $results[] = [
                                'service' => strtoupper($cost['code']) . ' - ' . $costDetail['service'],
                                'cost' => $costDetail['cost'][0]['value'],
                                'etd' => $costDetail['cost'][0]['etd'],
                                'courier' => $code,
                            ];
                        }
                    }
                }
            } else {
                \Log::warning("RAJAONGKIR [$code] returned no cost data", $params);
            }
        }

        return [
            'origin' => $this->rajaOngkirOrigin,
            'destination' => $destination,
            'weight' => $weight,
            'results' => $results,
        ];

		\Log::info('DEBUG CART BERAT:', [
			'items' => \Cart::getContent()->map(function ($item) {
				return [
					'product_id' => $item->associatedModel->id ?? null,
					'name' => $item->name,
					'qty' => $item->quantity,
					'weight' => $item->associatedModel->weight ?? null,
				];
			})->toArray(),
		]);
		
    }


    public function setShipping(Request $request)
    {
        \Cart::removeConditionsByType('shipping');

        $items = \Cart::getContent();
        $totalWeight = 0;

        foreach ($items as $item) {
            $totalWeight += ($item->quantity * $item->associatedModel->weight);
        }

        $shippingService = $request->get('shipping_service');
        $destination = $request->get('city_id');

        $shippingOptions = $this->getShippingCost($destination, $totalWeight);

        $selectedShipping = null;
        if (!empty($shippingOptions['results'])) {
            foreach ($shippingOptions['results'] as $shippingOption) {
                if (str_replace(' ', '', $shippingOption['service']) == $shippingService) {
                    $selectedShipping = $shippingOption;
                    break;
                }
            }
        }

        if ($selectedShipping) {
            $this->addShippingCostToCart($selectedShipping['service'], $selectedShipping['cost']);
            return [
                'status' => 200,
                'message' => 'Shipping cost set',
                'data' => [
                    'total' => number_format(\Cart::getTotal())
                ]
            ];
        }

        return [
            'status' => 400,
            'message' => 'Shipping service tidak ditemukan'
        ];
    }

    private function addShippingCostToCart($serviceName, $cost)
    {
        $condition = new \Darryldecode\Cart\CartCondition([
            'name' => $serviceName,
            'type' => 'shipping',
            'target' => 'total',
            'value' => '+' . $cost,
        ]);

        \Cart::condition($condition);
    }

    private function getSelectedShipping($destination, $totalWeight, $shippingService)
    {
        $shippingOptions = $this->getShippingCost($destination, $totalWeight);

        \Log::info('Comparing shipping service', [
            'submitted' => $shippingService,
            'available_options' => $shippingOptions['results']
        ]);

        foreach ($shippingOptions['results'] as $shippingOption) {
            if (str_replace(' ', '', $shippingOption['service']) == $shippingService) {
                return $shippingOption;
            }
        }

        return null;
    }

    public function checkout(Request $request)
    {
        $params = $request->except('_token');

        $order = \DB::transaction(function () use ($params) {
            $destination = isset($params['ship_to']) ? $params['shipping_city_id'] : $params['city_id'];

            $items = \Cart::getContent();
            $totalWeight = 0;
            foreach ($items as $item) {
                $totalWeight += ($item->quantity * $item->associatedModel->weight);
            }

            $selectedShipping = $this->getSelectedShipping($destination, $totalWeight, $params['shipping_service']);

            if (!$selectedShipping) {
                throw new \Exception('Shipping service tidak valid atau tidak ditemukan.');
            }

            $baseTotalPrice = \Cart::getSubTotal();
            $shippingCost = $selectedShipping['cost'];
            $grandTotal = $baseTotalPrice + $shippingCost;

            $orderDate = now();
            $paymentDue = $orderDate->copy()->addDays(3);

            auth()->user()->update([
                'username' => $params['username'],
                'first_name' => $params['first_name'],
                'last_name' => $params['last_name'],
                'address1' => $params['address1'],
                'address2' => $params['address2'],
                'province_id' => $params['province_id'],
                'city_id' => $params['city_id'],
                'postcode' => $params['postcode'],
                'phone' => $params['phone'],
                'email' => $params['email'],
            ]);

            $order = Order::create([
                'user_id' => auth()->id(),
                'code' => Order::generateCode(),
                'status' => Order::CREATED,
                'order_date' => $orderDate,
                'payment_due' => $paymentDue,
                'payment_status' => Order::UNPAID,
                'base_total_price' => $baseTotalPrice,
                'discount_amount' => 0,
                'discount_percent' => 0,
                'shipping_cost' => $shippingCost,
                'grand_total' => $grandTotal,
                'customer_first_name' => $params['first_name'],
                'customer_last_name' => $params['last_name'],
                'customer_address1' => $params['address1'],
                'customer_address2' => $params['address2'],
                'customer_phone' => $params['phone'],
                'customer_email' => $params['email'],
                'customer_city_id' => $params['city_id'],
                'customer_province_id' => $params['province_id'],
                'customer_postcode' => $params['postcode'],
                'note' => $params['note'],
                'shipping_courier' => $selectedShipping['courier'],
                'shipping_service_name' => $selectedShipping['service'],
            ]);

            foreach (\Cart::getContent() as $item) {
                OrderItem::create([
                    'order_id' => $order->id,
                    'product_id' => $item->associatedModel->id,
                    'qty' => $item->quantity,
                    'base_price' => $item->price,
                    'base_total' => $item->price * $item->quantity,
                    'discount_amount' => 0,
                    'discount_percent' => 0,
                    'sub_total' => $item->price * $item->quantity,
                    'name' => $item->name,
                    'weight' => $item->associatedModel->weight,
                ]);

                $product = Product::find($item->associatedModel->id);
                if ($product) {
                    $product->decrement('quantity', $item->quantity);
                }
            }

            Shipment::create([
                'user_id' => auth()->id(),
                'order_id' => $order->id,
                'status' => Shipment::PENDING,
                'total_qty' => \Cart::getTotalQuantity(),
                'total_weight' => $totalWeight,
                'first_name' => isset($params['ship_to']) ? $params['shipping_first_name'] : $params['first_name'],
                'last_name' => isset($params['ship_to']) ? $params['shipping_last_name'] : $params['last_name'],
                'address1' => isset($params['ship_to']) ? $params['shipping_address1'] : $params['address1'],
                'address2' => isset($params['ship_to']) ? $params['shipping_address2'] : $params['address2'],
                'phone' => isset($params['ship_to']) ? $params['shipping_phone'] : $params['phone'],
                'email' => isset($params['ship_to']) ? $params['shipping_email'] : $params['email'],
                'city_id' => $destination,
                'province_id' => isset($params['shipping_province_id']) ? $params['shipping_province_id'] : $params['province_id'],
                'postcode' => isset($params['shipping_postcode']) ? $params['shipping_postcode'] : $params['postcode'],
            ]);

            return $order;
        });

        \Cart::clear();
        $this->initPaymentGateway();

        $transaction_details = [
            'transaction_details' => [
                'order_id' => $order->code,
                'gross_amount' => $order->grand_total,
            ],
            'customer_details' => [
                'first_name' => $order->customer_first_name,
                'last_name' => $order->customer_last_name,
                'email' => $order->customer_email,
                'phone' => $order->customer_phone,
            ],
            'expiry' => [
                'start_time' => now()->toIso8601String(),
                'unit' => Payment::EXPIRY_UNIT,
                'duration' => Payment::EXPIRY_DURATION,
            ],
            'enable_payments' => Payment::PAYMENT_CHANNELS,
        ];

        try {
            $snap = Snap::createTransaction($transaction_details);
            $order->update([
                'payment_token' => $snap->token,
                'payment_url' => $snap->redirect_url,
            ]);
            return redirect($snap->redirect_url);
        } catch (Exception $e) {
            \Log::error("Midtrans error: " . $e->getMessage());
            return redirect()->back()->with('error', 'Gagal memproses pembayaran.');
        }
    }

    public function received($orderId)
    {
        $order = Order::where('id', $orderId)->where('user_id', auth()->id())->firstOrFail();
        return view('frontend.orders.received', compact('order'));
    }

    public function index()
    {
        $orders = Order::where('user_id', auth()->id())->paginate(10);
        return view('frontend.orders.index', compact('orders'));
    }

    public function show($id)
    {
        $order = Order::where('user_id', auth()->id())->findOrFail($id);
        return view('frontend.orders.show', compact('order'));
    }
}
