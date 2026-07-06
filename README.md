# Tino Reseller Module for HostBill

Provisioning module that lets a HostBill instance resell Tino services
(hosting, VPS, domains) by placing orders through the Tino REST API
(`https://api.tino.vn`).

## How it works

- **Server connection** stores the reseller's Tino account. The module logs in
  (`POST /login`), caches the JWT access + refresh tokens in HostBill's cache
  (`HBCache`), and refreshes them automatically. No re-login per request.
- **Product configuration** uses dynamic dropdowns fetched from the Tino API:
  Category → Product → Billing Cycle. The catalog is cached; a **Reload catalog**
  button forces a refresh.
- **Ordering** calls `POST /order/{product_id}` with a Bearer token. `pay_method`
  is intentionally omitted so HostBill/Tino settles the order from account credit.

## Server setup (in HostBill)

1. Add a server using the **Tino** module.
2. **Username / Password**: your Tino account credentials (`tino.vn`).
3. **Environment**: choose **Live** or **OTE**.
   - Live → `https://api.tino.vn`
   - OTE  → `https://ote.tino.vn/api/`
4. Use **Test Connection** — it performs a real login.

## Product setup

1. Create a product using the Tino module and select the server.
2. In the module config, pick **Category**, **Product**, and **Billing Cycle**
   (loaded live from Tino). Optionally set a **Promotion Code** / **Affiliate ID**.

## Lifecycle → Tino API mapping

| HostBill        | Tino API                              |
|-----------------|---------------------------------------|
| Create          | `POST /order/{product_id}`            |
| Terminate       | `POST /service/{id}/cancel`           |
| Change Package  | `POST /service/{id}/upgrade`          |
| Suspend         | `POST /service/{id}/suspend`  ⚠️       |
| Unsuspend       | `POST /service/{id}/unsuspend` ⚠️      |

### Order response (verified)

`POST /order/{id}` returns, on success:

```json
{
  "order_num": 1132559230,
  "invoice_id": "1199323",
  "total": 0,
  "items": [
    { "type": "Hosting", "id": "356472", "name": "example.com", "product_id": "1" }
  ]
}
```

- **Service id** = `items[].id` (persisted as `option1`).
- **Order id** = `order_num` (persisted as `option3`).
- The service is created in **Pending** status and provisioned shortly after
  (asynchronous). If `items[].id` is absent, the module looks the service up by
  domain via `GET /service`.
- If an order succeeds but no id can be captured, Create **fails** (returns
  false) rather than marking the service Active — this prevents a retry from
  placing a duplicate order.

### Terminate / Cancel behaviour

`POST /service/{id}/cancel` returns `{"info":["cancell_sent"]}` — it **queues** a
cancellation request (processed by Tino), it does not delete the service
immediately. The service stays Active until Tino processes the request.

> ⚠️ **Suspend / Unsuspend**: these reseller endpoints are not yet available in
> the Tino user API. The module already calls them; they will work once Tino
> ships `POST /service/{id}/suspend` and `POST /service/{id}/unsuspend`. Until
> then, suspend/unsuspend actions will report an API error.

## Notes

- SSL certificate verification is **enabled** (Tino API is public HTTPS).
- No custom database tables — token and catalog caches use `HBCache`.
- Tokens are scoped per server id, so multiple Tino accounts don't share cache.
