<?php

class Partner {

	public function __construct()
	{
		$this->app_id = Configuration::get('YA_POKUPKI_ID');
		$this->url = Configuration::get('YA_POKUPKI_APIURL');
		$this->number = Configuration::get('YA_POKUPKI_NC');
		$this->login = Configuration::get('YA_POKUPKI_LOGIN');
		$this->app_pw = Configuration::get('YA_POKUPKI_PW');
		$this->token = Configuration::get('YA_POKUPKI_TOKEN');
		$this->ya_token = Configuration::get('YA_POKUPKI_YATOKEN');
		$this->context = Context::getContext();
		$this->module = Module::getInstanceByName('yamodule');
	}

	public function getOrders()
	{
		return $data = $this->SendResponse('/campaigns/'.$this->number.'/orders', array(), array(), 'GET');
	}

	public function getOutlets()
	{
		$data = $this->SendResponse('/campaigns/'.$this->number.'/outlets', array(), array(), 'GET');
		$array = array('outlets' => array());
		foreach($data->outlets as $o)
			$array['outlets'][] = array('id' => (int)$o->shopOutletId);
		$return = array(
			'json' => $array,
			'array' => $data->outlets
		);

		return $return;
	}

	public function getOrder($id)
	{
		$data = $this->SendResponse('/campaigns/'.$this->number.'/orders/'.$id, array(), array(), 'GET');
		return $data;
	}

	public function sendOrder($state, $id)
	{
		$params = array(
			'order' => array(
				'status' => $state,
			)
		);

		if($state == 'CANCELLED')
			$params['order']['substatus'] = 'SHOP_FAILED';

		return $data = $this->SendResponse('/campaigns/'.$this->number.'/orders/'.$id.'/status', array(), $params, 'PUT');
	}

	public function sendDelivery($order)
	{
		$order_ya_db = $this->module->getYandexOrderById($order->id);
		$ya_order = $this->getOrder($order_ya_db['id_market_order']);
		$address = new Address($order->id_address_delivery);
		$carrier = New Carrier($order->id_carrier, $this->context->language->id);
		$country = new Country($address->id_country, $this->context->language->id);
		$date_time_string = explode(' ', $order->delivery_date);
		$types = unserialize(Configuration::get('YA_POKUPKI_CARRIER_SERIALIZE'));
		$type = $types[$carrier->id] ? $types[$carrier->id] : 'POST';
		$params = array(
			'delivery' => array(
				'id' => $carrier->id,
				'type' => $type,
				'serviceName' => $carrier->name.'('.$carrier->delay.')',
				'dates' => array(
					'fromDate' => $date_time_string[0] > 0 ? date('d-m-Y', strtotime($date_time_string[0])) : date('d-m-Y'),
				)
			)
		);
		
		if($ya_order->order->paymentType == 'POSTPAID')
			$params['delivery']['price'] = $order->total_shipping;

		if($type == 'PICKUP')
			$params['delivery']['outletId'] = $order_ya_db['outlet'];
		else
			$params['delivery']['address'] = array(
					'country' => $country->name,
					'postcode' => $address->postcode,
					'city' => $address->city,
					'house' => $order_ya_db['home'],
					'street' => $address->address1.' '.($address->address2 ? $address->address2 : ''),
					'recipient' => $address->firstname.' '.$address->lastname,
					'phone' => $address->phone_mobile ? $address->phone_mobile : $address->phone,
				);

		return $data = $this->SendResponse('/campaigns/'.$this->number.'/orders/'.$order_ya_db['id_market_order'].'/delivery', array(), $params, 'PUT');
	}

	public function addData($data, $add, $type)
	{
		$delivery = array();
		$cart = new Cart();
		if($data->$type->currency == 'RUR')
			$currency_id = Currency::getIdByIsoCode('RUB');
		else
			$currency_id = Currency::getIdByIsoCode($data->cart->currency);
		$def_currency = Configuration::get('PS_CURRENCY_DEFAULT');
		$this->context->cookie->id_currency = ($def_currency != $currency_id) ? $currency_id : $def_currency;
		$this->context->cookie->write();
		$this->context->currency = new Currency($this->context->cookie->id_currency);
		$cart->id_lang = (int)$this->context->cookie->id_lang;
		$cart->id_currency = (int)$this->context->cookie->id_currency;
		$cart->id_guest = (int)$this->context->cookie->id_guest;
		$cart->add();
		$this->context->cookie->id_cart = (int)$cart->id;
		$this->context->cookie->write();
		$b = array();
		if($add)
		{
			$street = isset($delivery->street) ? ' Улица: '.$delivery->street : 'Самовывоз';
			$subway = isset($delivery->subway) ? ' Метро: '.$delivery->subway : '';
			$block = isset($delivery->block) ? ' Корпус/Строение: '.$delivery->block : '';
			$floor = isset($delivery->floor) ? ' Этаж: '.$delivery->floor : '';
			$address1 = $street.$subway.$block.$floor;
			$customer = new Customer(Configuration::get('YA_POKUPKI_CUSTOMER'));
			$delivery = isset($data->$type->delivery->address) ? $data->$type->delivery->address : new stdClass();
			$address = new Address();
			$address->firstname = 'test';
			$address->lastname = 'test';
			$address->phone_mobile = 999999;
			$address->postcode = isset($delivery->postcode) ? $delivery->postcode : 000000;
			$address->address1 = $address1;
			$address->city = isset($delivery->city) ? $delivery->city : 'Город';
			$address->alias = 'pokupki_' . substr(md5(time()._COOKIE_KEY_), 0, 7);
			$address->id_customer = $customer->id;
			$address->id_country = Configuration::get('PS_COUNTRY_DEFAULT');
			$address->save();
			$cart->id_address_invoice = (int)($address->id);
			$cart->id_address_delivery = (int)($address->id);
			$id_address = (int)($address->id);
			$cart->update();
			$cart->id_customer = (int)$customer->id;
			$this->context->cookie->id_customer = (int)$customer->id;
			$this->context->cookie->write();
			$b = array(
				'address' => $address,
				'customer' => $customer
			);
		}
		CartRule::autoRemoveFromCart($this->context);
		CartRule::autoAddToCart($this->context);
		$a = array(
			'cart' => $cart,
		);
		$dd = array_merge($a, $b);
		return $dd;
	}

	public function requestItems($data)
	{
		$delivery = array();
		$items = $data->cart->items;
		if(isset($data->cart->delivery->address))
			$delivery = $data->cart->delivery->address;
		if(count($items))
		{
			if($delivery)
			{
				$d = $this->addData($data, true, 'cart');
				$customer = $d['customer'];
				$address = $d['address'];
			}
			else
				$d = $this->addData($data, false, 'cart');
			$cart = $d['cart'];
			$tovar = array();
			foreach($items as $item)
			{
				$id_a = null;
				$id = explode('c', $item->offerId);
				$product = new Product($id[0], true, $this->context->cookie->id_lang);
				if(isset($id[1]))
					$id_a = (int)$id[1];

				$count_shop = StockAvailable::getQuantityAvailableByProduct($product->id, $id_a);
				if(!$product->active || $count_shop < (int)$item->count)
					continue;

				$count = min($count_shop, (int)$item->count);
				if($id_a)
				{
					$comb = new Combination($id_a);
					if ($count < $comb->minimal_quantity)
						continue;
				}
				else
					if ($count < $product->minimal_quantity)
						continue;

				$price = Product::getPriceStatic($product->id, null, $id_a);
				$result = $cart->updateQty((int)$item->count, (int)$id[0], $id_a);
				$total = Tools::ps_round($price, 2);
				if($result)
				{
					$tovar[] = array(
						'feedId' => $item->feedId,
						'offerId' => $item->offerId,
						'price' => $total,
						'count' => (int)$count,
						'delivery' => true,
					);

					$cart->update();
				}
			}
			$dost = array();
			$pm = array();
			$types = unserialize(Configuration::get('YA_POKUPKI_CARRIER_SERIALIZE'));
			foreach($cart->simulateCarriersOutput() as $k => $d)
			{
				$id = str_replace(',', '', Cart::desintifier($d['id_carrier']));
				$type = $types[$id] ? $types[$id] : 'POST';
				$dost[$k] = array(
					'id' => $id,
					'serviceName' => $d['name'],
					'type' => $type,
					'price' => $d['price'],
					'dates' => array(
						'fromDate' => date('d-m-Y'),
						'toDate' => date('d-m-Y'),
					),
				);

				if($type == 'PICKUP')
				{
					$outlets = $this->getOutlets();
					$dost[$k] = array_merge($dost[$k], $outlets['json']);
				}
			}

			if (Configuration::get('YA_POKUPKI_PREDOPLATA_YANDEX'))
				$pm[] = 'YANDEX';

			if (Configuration::get('YA_POKUPKI_PREDOPLATA_SHOP_PREPAID'))
				$pm[] = 'SHOP_PREPAID';

			if (Configuration::get('YA_POKUPKI_POSTOPLATA_CASH_ON_DELIVERY'))
				$pm[] = 'CASH_ON_DELIVERY';

			if (Configuration::get('YA_POKUPKI_POSTOPLATA_CARD_ON_DELIVERY'))
				$pm[] = 'CARD_ON_DELIVERY';


			$array = array(
				'cart' => array(
					'items' => $tovar,
					'deliveryOptions' => $dost,
					'paymentMethods' => $pm
				)
			);

			$cart->delete();
			$this->context->cookie->logout();
			if($delivery)
				$address->delete();

			die(Tools::jsonEncode($array));
		}
	}

	public function alertOrderStatus($data)
	{
		$order = $this->module->getOrderByYaId((int)$data->order->id);
		if($order->id_cart > 0)
		{
			$status = $data->order->status;
			if($status == 'CANCELLED')
				$order->setCurrentState((int)$this->module->status['CANCELLED']);

			if($status == 'PROCESSING')
				$order->setCurrentState((int)$this->module->status['PROCESSING']);

			if($status == 'UNPAID')
				$order->setCurrentState($this->module->status['UNPAID']);

			die(1);
		}
	}

	public function orderAccept($data)
	{
		$delivery = '';
		$array = array();
		$items = $data->order->items;
		if(isset($data->order->delivery->address))
			$delivery = $data->order->delivery->address;
		if(count($items))
		{
			$d = $this->addData($data, true, 'order');
			$cart = $d['cart'];
			$customer = $d['customer'];
			$address = $d['address'];

			foreach($items as $item)
			{
				$id_a = null;
				$id = explode('c', $item->offerId);
				$product = new Product($id[0], true, $this->context->cookie->id_lang);
				if(isset($id[1]))
					$id_a = (int)$id[1];

				$count_shop = StockAvailable::getQuantityAvailableByProduct($product->id, $id_a);
				if(!$product->active || $count_shop < (int)$item->count)
					continue;

				$result = $cart->updateQty((int)$item->count, (int)$id[0], $id_a);
				if($result)
					$cart->update();
			}

			if(count($items) == count($cart->getProducts()) && isset($data->order->paymentMethod) && isset($data->order->paymentType))
			{
				$resultat = false;
				if($data->order->delivery->id > 0)
				{
					$do = array($address->id => $data->order->delivery->id.',');
					$cart->setDeliveryOption($do);
				}
				
				$mailVars = array();
				$message = '';
				if(isset($data->order->notes))
					$message = $data->order->notes ? $data->order->notes : null;
				$currency = $this->context->currency;
				$total = (float)$cart->getOrderTotal(true, Cart::BOTH);
				$order = new YaOrderCreate();
				$order->name = $data->order->paymentType.'_'.$data->order->paymentMethod;
				$order->module = 'yamodule';
				$total = $this->context->cart->getOrderTotal(true, Cart::BOTH);
				$res = $order->validateOrder((int)$cart->id, $this->module->status['MAKEORDER'], $total, 'Yandex.Market.Order', $message, array(), null, false, ($cart->secure_key ? $cart->secure_key : ($customer->secure_key ? $customer->secure_key : false)));
				if($res)
				{
					$values_to_insert = array(
						'id_order' => (int)$order->currentOrder,
						'id_market_order' => (int)$data->order->id,
						'ptype' => $data->order->paymentType,
						'pmethod' => $data->order->paymentMethod,
						'home' => isset($data->order->delivery->address->house) ? $data->order->delivery->address->house : 0,
						'outlet' => isset($data->order->delivery->outlet->id) ? $data->order->delivery->outlet->id : '',
						'currency' => $data->order->currency
					);

					Db::getInstance()->autoExecute(_DB_PREFIX_.'pokupki_orders', $values_to_insert, 'INSERT');
					$resultat = true;
				}
				else
					$resultat = false;
			}
			else
				$resultat = false;
		}
		else
			$resultat = false;

		if($resultat)
		{
			$array = array(
				'order' => array(
					'accepted' => true,
					'id' => ''.$order->currentOrder.'',
				)
			);
		}
		else
		{
			$array = array(
				'order' => array(
					'accepted' => false,
					'reason' => 'OUT_OF_DATE'
				)
			);
		}

		die(Tools::jsonEncode($array));
	}

	public function SendResponse($to, $headers, $params, $type)
	{
		$response = $this->post($this->url.$to.'.json?oauth_token='.$this->ya_token.'&oauth_client_id='.$this->app_id.'&oauth_login='.$this->login, $headers, $params, $type);
		$data = Tools::jsonDecode($response->body);
		if(isset($data->error))
			$this->module->log_save($response->body);
		if($response->status_code == 200)
			return $data;
		else
			Tools::d($response);
	}

	public static function post($url, $headers, $params, $type){
		$curlOpt = array(
			CURLOPT_HEADER => 0,
			CURLOPT_RETURNTRANSFER => 1,
			CURLOPT_FOLLOWLOCATION => 1,
			CURLINFO_HEADER_OUT => 1,
			CURLOPT_MAXREDIRS => 3,
			CURLOPT_CONNECTTIMEOUT => 30,
			CURLOPT_TIMEOUT => 80,
			CURLOPT_SSL_VERIFYPEER => true,
			CURLOPT_USERAGENT => 'php-market',
			CURLOPT_CAINFO => dirname(__FILE__).'/../lib/data/ca-certificate.crt',
		);

		switch (strtoupper($type)){
			case 'DELETE':
				$curlOpt[CURLOPT_CUSTOMREQUEST] = "DELETE";
			case 'GET':
				if (!empty($params))
					$url .= (strpos($url, '?')===false ? '?' : '&') . http_build_query($params);
			break;
			case 'PUT':
				$headers[] = 'Content-Type: application/json;';
				$body = Tools::jsonEncode($params);
				$fp = tmpfile();
				fwrite($fp, $body, strlen($body));
				fseek($fp, 0);
				$curlOpt[CURLOPT_PUT] = true;
				$curlOpt[CURLOPT_INFILE] = $fp;
				$curlOpt[CURLOPT_INFILESIZE] = strlen($body);
			break;
		}

		$curlOpt[CURLOPT_HTTPHEADER] = $headers;
		$curl = curl_init($url);
		curl_setopt_array($curl, $curlOpt);
		$rbody = curl_exec($curl);
		$errno = curl_errno($curl);
		$error = curl_error($curl);
		$rcode = curl_getinfo($curl, CURLINFO_HTTP_CODE);
		// Tools::d(curl_getinfo($curl, CURLINFO_HEADER_OUT));
		curl_close($curl);
		$result = new stdClass();
		$result->status_code = $rcode;
		$result->body = $rbody;
		$result->error = $error;
		return $result;
	}
}