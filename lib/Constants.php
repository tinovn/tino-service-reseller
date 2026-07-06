<?php

namespace Hosting\Tino\lib;

/**
 * Shared constants for the Tino reseller module.
 *
 * Groups:
 *  - STATUS_*  service status values returned by the Tino API
 *  - D_*       per-account detail fields (mapped to HostBill option1..optionN)
 *  - O_*       product config option keys (shown in HostBill product setup)
 */
interface Constants
{
    // -------------------------------------------------------------------------
    // Service status (as returned by Tino API)
    // -------------------------------------------------------------------------
    const STATUS_ACTIVE     = 'Active';
    const STATUS_SUSPENDED  = 'Suspended';
    const STATUS_CANCELLED  = 'Cancelled';
    const STATUS_PENDING    = 'Pending';

    // -------------------------------------------------------------------------
    // Per-account detail fields (stored by HostBill against each service)
    // -------------------------------------------------------------------------
    const D_SERVICE_ID = 'option1'; // Tino service id (returned after ordering)
    const D_DOMAIN     = 'option2'; // Domain submitted with the order
    const D_ORDER_ID   = 'option3'; // Tino order/invoice id (for reference)

    // -------------------------------------------------------------------------
    // Product config option keys (HostBill product setup)
    // -------------------------------------------------------------------------
    const O_CATEGORY_ID = 'Category';    // Tino category id (dynamic select)
    const O_PRODUCT_ID  = 'Product';     // Tino product id (dynamic select)
    const O_CYCLE       = 'Cycle';       // Billing cycle symbol (m/q/s/a/b/t)
    const O_PROMOCODE   = 'Promocode';   // Optional promotion code
    const O_AFF_ID      = 'Affiliate ID'; // Optional affiliate id
}
