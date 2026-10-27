<?php

namespace App\Enums;

enum DeliveryMethod: string
{
    case ShopPickup = 'shop_pickup';
    case Delivery = 'delivery';
}
