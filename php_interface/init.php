<?php

if (!CModule::IncludeModule("sale")) {
    return;
}

use Bitrix\Main;
use Bitrix\Sale;

class OrderHandler
{
    private static $isProcessing = false;

    public static function init()
    {
        AddEventHandler("sale", "OnSaleOrderSaved", [self::class, "SplitOrderByProperties"]);
    }

    public static function SplitOrderByProperties($order)
    {
        if (self::$isProcessing) {
            logToFile("Обработка уже запущена для заказа ID: " . $order->getId());
            return;
        }

        self::$isProcessing = true;

        logToFile("Начало обработки события OnSaleOrderSaved");

        try {
            $basket = $order->getBasket();
            $siteId = $order->getSiteId();
            $userId = $order->getUserId();
            $currency = $order->getCurrency();

            logToFile("Получен заказ: ID = " . $order->getId() . ", UserID = " . $userId);

            if (!$basket || count($basket->getBasketItems()) == 0) {
                logToFile("Ошибка: Корзина заказа пуста. ID заказа: " . $order->getId());
                return;
            }

            $itemsGroupedByProperties = [];
            $basketItems = $basket->getBasketItems();

            $itemIds = [];
            foreach ($basketItems as $basketItem) {
                $itemIds[] = $basketItem->getProductId();
            }

            $elementInfo = CIBlockElement::GetList(
                [],
                ["ID" => $itemIds],
                false,
                false,
                ["ID", "PROPERTY_COLOR_LIST", "PROPERTY_MANUFACTURER_LIST"]
            );

            $elementData = [];
            while ($element = $elementInfo->Fetch()) {
                $elementData[$element['ID']] = $element;
            }

            foreach ($basketItems as $basketItem) {
                $itemId = $basketItem->getProductId();
                if (isset($elementData[$itemId])) {
                    $element = $elementData[$itemId];
                    $color = $element["PROPERTY_COLOR_LIST_VALUE"];
                    $manufacturer = $element["PROPERTY_MANUFACTURER_LIST_VALUE"];
                    logToFile("Товар с ID = " . $itemId . " имеет свойства: COLOR = " . $color . ", MANUFACTURER = " . $manufacturer);

                    $itemsGroupedByProperties[$color][$manufacturer][] = $basketItem;
                } else {
                    logToFile("Ошибка: Товар с ID = " . $itemId . " не найден или у него отсутствуют необходимые свойства.");
                }
            }

            logToFile("Группировка товаров завершена");

            foreach ($itemsGroupedByProperties as $colorGroup => $manufacturers) {
                foreach ($manufacturers as $manufacturerGroup => $items) {
                    logToFile("Создание нового заказа для группы: COLOR = " . $colorGroup . ", MANUFACTURER = " . $manufacturerGroup);

                    $connection = \Bitrix\Main\Application::getConnection();
                    $connection->startTransaction();

                    try {
                        $newOrder = \Bitrix\Sale\Order::create($siteId, $userId);
                        $newOrder->setPersonTypeId($order->getPersonTypeId());
                        $newOrder->setField("CURRENCY", $currency);

                        $shipmentCollection = $order->getShipmentCollection();
                        $paymentCollection = $order->getPaymentCollection();

                        $newShipmentCollection = $newOrder->getShipmentCollection();
                        $newPaymentCollection = $newOrder->getPaymentCollection();

                        foreach ($shipmentCollection as $shipment) {
                            $newShipment = $newShipmentCollection->createItem($shipment->getDelivery());
                            $newShipment->setFields([
                                'DELIVERY_ID' => $shipment->getDeliveryId(),
                                'DELIVERY_NAME' => $shipment->getDeliveryName(),
                                'STATUS_ID' => $shipment->getField('STATUS_ID'),
                                'DEDUCTED' => $shipment->getField('DEDUCTED'),
                                'ALLOW_DELIVERY' => $shipment->getField('ALLOW_DELIVERY'),
                                'PRICE_DELIVERY' => $shipment->getField('PRICE_DELIVERY')
                            ]);
                        }

                        foreach ($paymentCollection as $payment) {
                            $newPayment = $newPaymentCollection->createItem($payment->getPaySystem());
                            $newPayment->setFields([
                                'PAY_SYSTEM_ID' => $payment->getPaymentSystemId(),
                                'PAY_SYSTEM_NAME' => $payment->getPaymentSystemName(),
                                'SUM' => $newOrder->getPrice()
                            ]);
                        }

                        $propertyCollection = $order->getPropertyCollection();
                        $newPropertyCollection = $newOrder->getPropertyCollection();

                        foreach ($propertyCollection as $property) {
                            $newPropertyValue = $newPropertyCollection->getItemByOrderPropertyId($property->getPropertyId());
                            if ($newPropertyValue) {
                                $newPropertyValue->setValue($property->getValue());
                            }
                        }

                        $newBasket = \Bitrix\Sale\Basket::create($siteId);
                        $newBasket->setFUserId($userId);

                        $shipmentItemCollection = $newShipment->getShipmentItemCollection();

                        foreach ($items as $item) {
                            logToFile("Добавляем товар в новый заказ: ID = " . $item->getProductId());
                            $newItem = $newBasket->createItem('catalog', $item->getProductId());
                            $newItem->setFields([
                                'NAME' => $item->getField('NAME'),
                                'QUANTITY' => $item->getQuantity(),
                                'PRICE' => $item->getPrice(),
                                'CURRENCY' => $currency,
                                'LID' => $siteId,
                                'CUSTOM_PRICE' => 'Y',
                                'BASE_PRICE' => $item->getBasePrice(),
                                'DISCOUNT_PRICE' => $item->getDiscountPrice(),
                            ]);

                            $properties = [];
                            $propertyCollection = $item->getPropertyCollection();
                            if ($propertyCollection) {
                                foreach ($propertyCollection as $property) {
                                    $value = $property->getField("VALUE");
                                    if ($value !== null) {
                                        $properties[] = [
                                            'NAME' => $property->getField("NAME"),
                                            'VALUE' => $value,
                                        ];
                                    }
                                }

                                //logToFile(print_r($properties, true));

                                // foreach ($properties as $property) {
                                //     foreach ($property as $code => $value) {
                                //         $newItem->setFieldNoDemand($code, $value);
                                //     }
                                // }
                            }

                            try {
                                logToFile("Создание элемента отгрузки для товара ID: " . $newItem->getId() . " с количеством: " . $newItem->getQuantity());
                            
                                $shipmentItem = $shipmentItemCollection->createItem($newItem);
                                $shipmentItem->setQuantity($newItem->getQuantity());

                                logToFile("Элемент отгрузки создан: ID = " . $shipmentItem->getId() . ", количество установлено: " . $shipmentItem->getQuantity());
                            } catch (Exception $e) {
                                logToFile("Ошибка при создании элемента отгрузки: " . $e->getMessage());
                            }

                            logToFile("Товар с ID = " . $item->getProductId() . " добавлен в новый заказ.");
                        }

                        $newBasket->save();
                        $newOrder->setBasket($newBasket);

                        $newOrder->doFinalAction(true);

                        $result = $newOrder->save();

                        if ($result->isSuccess()) {
                            logToFile("Новый заказ успешно создан: ID = " . $newOrder->getId());
                        } else {
                            logToFile("Ошибка создания заказа: " . implode("\n", $result->getErrorMessages()));
                            throw new \Bitrix\Main\SystemException("Ошибка создания заказа");
                        }

                        $connection->commitTransaction();
                    } catch (\Exception $e) {
                        logToFile("Исключение в транзакции: " . $e->getMessage());
                        logToFile("Стек вызовов: " . $e->getTraceAsString());
                        $connection->rollbackTransaction();
                    }
                }
            }

            $order->save();
        } catch (\Exception $e) {
            logToFile("Общая ошибка: " . $e->getMessage());
        }

        logToFile("Обработка заказа завершена для заказа ID: " . $order->getId());
        $order->delete($order->getId());
        self::$isProcessing = false;
    }
}

OrderHandler::init();

function logToFile($message)
{
    $filePath = __DIR__ . '/split_order.log';
    $date = date('Y-m-d H:i:s');
    $fullMessage = '[' . $date . '] ' . $message . "\n";
    file_put_contents($filePath, $fullMessage, FILE_APPEND);
}
