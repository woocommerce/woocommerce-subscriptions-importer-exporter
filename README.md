# WooCommerce Subscriptions Importer

The WooCommerce Subscriptions CSV Import Suite allows you to easily import your subscriptions from a CSV file straight into your WooCommerce Store. This is particularly useful when migrating stores and importing from a different eCommerce platform - which may or may not use exactly the same fields as WooCommerce.

## Before Installing - PayPal Standard is not supported!
Due to PayPal Standard limitations (more info [here](https://docs.woothemes.com/document/subscriptions/payment-gateways/#paypal-limitations)) the importer will not allow you to migrate PayPal Standard subscriptions. Note that these limitations do not apply to PayPal Reference Transactions and therefore the CSV importer can migrate your RT customers.

If you have subscriptions with PayPal Standard and you're interested in getting your customers to use a different payment method, then please try using the approach explained in our documentation here: https://docs.woothemes.com/document/subscriptions/customers-view/#section-5

## Importer guide - must read!
The following list of points can be taken to maximize success and minimize time and problems that occur while using this plugin:

1.  Read through this entire documentation so you know how the import process operates and its requirements. There is no substitution for this step; yes reading documentation is a pain and takes time, but it will actually save you time in the end.
2.  Make sure the `product_id` within the `order_items` column in the CSV exists (since WooCommerce Subscriptions v2.0 update, this product does not need to be a subscription product, it can be a standard WooCommerce Product or Variation).
3.  Set the column names and data formats for the files as described below in this documentation. This is not a requirement however if the columns in your CSV are named the same as described below, they will automatically be selected - saving you time.
4.  Before importing, run the importer in test mode first to avoid unexpected outcomes, test mode can be enabled by checking the box displayed on the home page. Enabling this option puts the importer in a test mode where all the errors and warnings will the caught and displayed to you before any subscriptions are created.
5.  Fix any errors and unnecessary warnings with your CSV file and repeat until the test run completes cleanly.
6.  __Do not!__ exit the import process or refresh the page mid-way through importing. Above the final import results table has a progress percentage indicator along with the expected time for the entire import to complete. We recommend leaving the importer running until you receive the timeout error which happens after 6 minutes of no requests coming through.
7.  Disable any order and subscription related emails you do not want to send before beginning the import.

# Formatting Guide
These are the general formatting rules which your CSV data must adhere to:
* The first row must include column headers - this is so the plugin knows where to map the fields.
* Each subscription needs to have its own row (note that all columsn don't need to be filled in)
* Two-letter country and state/county abbreviations should be used when possible.
* Date fields need to be in the format: y-m-d H:i:s (MySQL format - 2010-08-12 17:45:13)
* All dollar amounts need to be either an integer or decimal value for instance, “5.65”, “3”, “127.2” are all valid entries.

## Columns

|Column name|Format||
|---|---|---|
|`customer_id`|int|Can be left blank when creating a new user or if username and/or email of an existing user is present.|
|`customer_email`|String||
|`customer_username`|String||
|`customer_password`|String||
|`billing_first_name`|String|If empty, the `billing_first_name` from user meta will be used.|
|`billing_last_name`|String|If empty, the `billing_last_name` from user meta will be used.|
|`billing_address_1`|String|If empty, the `billing_address_1` from user meta will be used.|
|`billing_address_2`|String|If empty, the `billing_address_2` from user meta will be used.|
|`billing_city`|String|If empty, the `billing_city` from user meta will be used.|
|`billing_state`|String|If empty, the `billing_state` from user meta will be used.|
|`billing_postcode`|String|If empty, the `billing_postcode` from user meta will be used.|
|`billing_email`|String|If empty, the `billing_email` from user meta will be used.|
|`billing_phone`|String|If empty, the `billing_phone` from user meta will be used.|
|`billing_company`|String|If empty, the `billing_company` from user meta will be used.|
|`shipping_first_name`|String|If empty, the `shipping_first_name` from user meta will be used.|
|`shipping_last_name`|String|If empty, the `shipping_last_name` from user meta will be used.|
|`shipping_address_1`|String|If empty, the `shipping_address_1` from user meta will be used.|
|`shipping_address_2`|String|If empty, the `shipping_address_2` from user meta will be used.|
|`shipping_city`|String|If empty, the `shipping_city` from user meta will be used.|
|`shipping_state`|String|If empty, the `shipping_state` from user meta will be used.|
|`shipping_postcode`|String|If empty, the `shipping_postcode` from user meta will be used.|
|`shipping_country`|String|If empty, the `shipping_country` from user meta will be used.|
|`subscription_status`|String|Can be one of: `wc-active`, `wc-expired`, `wc-pending`, `wc-on-hold`, `wc-pending-cancel` or `wc-cancelled`. Defaults to `wc-pending`.|
|`start_date`|Y-m-d H:i:s|Defaults to the current time if left empty.|
|`trial_end_date`|Y-m-d H:i:s|If set, the trial end date must come after the start date. This will be caught in a test run.|
|`next_payment_date`|Y-m-d H:i:s|If set, the next payment date must come after the start date and trial end date. This date can be left empty. When the status is updated to `wc-active` the next payment date will be calculated|
|`end_date`|Y-m-d H:i:s|Can be left empty if you want your subscriptions to not expire (i.e. continuously renew until the customer cancels)|
|`billing_period`|String|Must be either: `day`, `week`, `month`, `year`. An invalid billing period will cause an error during the import and the subscription will not be imported.|
|`billing_interval`|int|Must be an integer value to represent how many subscription periods between each payment.|
|`cart_discount`|float|
|`cart_discount_tax`|float|
|`order_shipping_tax`|float|
|`order_shipping`|float|
|`order_tax`|float|
|`order_total`|float|
|`payment_method`|String|Set as the Gateway ID which can be seen in table at **WooCommerce > Settings > Checkout**. Leave this blank if you wish to import your subscriptions with manual renewals.|
|`payment_method_title`|String||
|`payment_method_post_meta`|array|See [Importing payment post and user meta](#importing-payment-post-and-user-meta) for more information.|
|`payment_method_user_meta`|array|See [Importing payment post and user meta](#importing-payment-post-and-user-meta) for more information.|
|`shipping_method`|mixed|This can be either the shipping method id as seen in the table at **WooCommerce > Settings > Shipping** page, i.e. `"free_shipping"` or `"flat_rate"`, or this can be an in the format `"shipping_id:flat_rate:|shipping_total:Flat Rate|total:10.00"`.|
|`download_permissions`|int|Can be either 1 (meaning that subscription needs download permissions granted) or 0.|
|`custom_user_meta`|mixed|Multiple columns can be mapped to this column and note that the value of the column header in the CSV will be used as the meta key. For example, if you want to add `'_terms' => true` as user meta. You will need to have a column in your CSV with header `_terms` and map it to `custom_user_meta`. Custom user meta is added to the user _before_ the subscription is created.|
|`custom_post_meta`|mixed|Multiple columns can be mapped to this column and note that the value of the column header in the CSV will be used as the meta key. For example, if you want to add `'_terms' => true` as post meta. You will need to have a column in your CSV with header `_terms` and map it to `custom_post_meta`. Custom user meta is added to the user _before_ the subscription is created while post meta is added immediately after.|
|`custom_user_post_meta`|mixed|Use this column if you wish to add custom data to both user and post meta using the same `meta_key` and value. Like previously stated, the value of the column header in the CSV will be used as the meta_key value.|
|`order_notes`|array|A string of order notes separated by the `;` symbol. For example `"Payment received.;Subscription activated."` will create two order notes on your subscription.|
|`customer_note`|String||
|`order_items`|mixed|Can simply be a product or variation ID. For more advanced imports see [Importing Order Items](#importing-order-items).|
|`coupon_items`|array|See section [Importing subscriptions with coupons](#importing-subscriptions-with-coupons) for more details.|
|`fee_items`|array|See [Attaching fees to your imported subscriptions](#attaching-fees-to-imported-subscriptions) for more info.|
|`tax_items`|array|Find more information under section: [Adding taxes to your imported subscriptions](#adding-taxes-to-imported-subscriptions).|


If any of the above columns contains invalid data, the importer will display these during in the test results. If you choose to ignore the errors and continue to import with invalid data, no subscription will be imported for that row in the CSV.


## Importing payment post and user meta
Properly importing the payment meta is probably the most difficult and most crucial part to importing and therfore it's important to take the time and get this right otherwise your subscription may stop renewing.

Firstly, the format of the `payment_method_post_meta` and `payment_method_user_meta` are the exact same.

### Supported Payment Gateways

The importer currently supports any payment gateway that supports the new  `woocommerce_subscription_payment_meta` filter introduced in WooCommerce Subscriptions v2.0.

Each payment method requires different pieces of information in order to renew properly, therefore here's a short list of the information needed and column headers for the more popular payment methods used on Subscriptions.
 * __PayPal Reference Transactions__: `_paypal_subscription_id`
 * __Stripe__: `_stripe_customer_id`
 * __Authorize.net CIM__: `wc_authorize_net_cim_customer_profile_id` and `wc_authorize_net_cim_payment_profile_id`

If you try to import a subscription using `stripe` but are missing the `_stripe_customer_id`, the subscription will not be created and the import will fail with a message detailing which payment meta is missing.

## Importing Order Items
The `order_items` column can be either an array of line item data, which can include line item totals and tax amounts or, it can be a single product or variation id of the item you want the subscription to have.

##### Single product/variation ID
When housing just a single ID in the `order_items` column, the only important to note is that all line item totals and subtotals will be defaulted to the products price amount and no amount will be added for tax.

##### Line item data
To add more information to your line items you need to follow strict formatting for it to properly import. Each order item needs to follow the following format: `product_id:5179|quantity:1|total:9.09|tax:0.91`. See the below table for a full list of line item data that can be used when importing line items.

| Data name | type ||
|---|---|---|
|product_id|int|**Required**. Must be either a product or variation ID of an existing product in your store. This does not specifically need to be a subscription product.|
|quantity|int|Defaults to 1.|
|total|float|Defaults to the products price.|
|subtotal|float|Defaults to the value used for total.|
|tax|float|Defaults to 0.|
|subtotal_tax|float|Defaults to 0.|

##### Importing subscriptions with multiple line items
To import multiple line items to your subscription, separate each line item data with a `;`, therefore a row inside your `order_items` column could look something like: `"product_id:5179|quantity:2|total:9.09|tax:0.91;product_id:2156|total:30"`. See the [sample import CSV]() for an example of importing multiple order items using the importer.

## Importing Subscriptions with Coupons
To attach coupons to your subscriptions you will need to know the coupon code and it will need to exist in your store at the time of importing. If it doesn't exist you will see the error `Could not find coupon with code "<your_code>" in your store.` and that row in your CSV will not be imported.

When formatting your `coupon_items` row, it will need to look like: `"code:summerdiscount2016|amount:15.00"`.

| Data name | type ||
|---|---|---|
|code|string|**Required**. Must be a valid coupon code which exists in your store at time of import.|
|amount|float|Sets the discount amount on the subscription. Defaults to discount amount stored on the coupon.|

You can attach multiple coupons/discounts to your imported subscription by separating each coupon item by the `;` character. For example: `"code:summerdiscount2016|amount:15.00;code:earlybird|amount:5.00"`.

![Multiple coupon items on subscription]()

## Attaching Fees to Imported Subscriptions
To attach fees to your imported subscriptions, the minimum requirement is the fee `name` field. An example of the `fee_items` column could look like: `"name:Handling|total:7.00|tax:0.70"`.

| Data name | type ||
|---|---|---|
|name|string|**Required**. Cannot attached fees to a subscription without a name. If this is empty, the subscription will not be imported and you will see the error: `"Fee name is missing from your CSV. This subscription has not been imported."`|
|total|float|Sets the fee amount on the subscription. Defaults to 0.|
|tax|float|Default 0.

You can attach multiple fees to an imported subscription by separating the fee items with the `;` symbol.

## Adding Taxes to Imported Subscriptions
The minimum requirement for attaching tax items is to have either the id or the code field. An example `tax_items` column would look like: `code:VAT` or `id:3`.

| Data name | type ||
|---|---|---|
|id|int|The unique tax rate ID found in DB.|
|code|string|The tax rate label|

There's a few simplifications when it comes to how the importer manages taxes. If you only provide the tax code in your CSV, the importer will query your available taxes and choose the tax with that code with the highest priority (i.e. if you have multiple taxes codes with VAT then it's possible the incorrect tax rate may be attached to your subscription). The alternative and more accurate workaround is to import tax items using the unique tax rate id (found in `{$wpdb->prefix}woocommerce_tax_rates` table under column `tax_rate_id`).

When the tax rate is successfully added to your subscription, this rate is then used as the tax class for all tax values for things like line items, shipping lines, fees etc.

## List of Warnings and Errors

#### Warnings

- Shipping method and title for the subscription have been left as empty.
- The following shipping address fields have been left empty: [ LIST_OF_FIELDS ].
- The following billing address fields have been left empty: [ LIST_OF_FIELDS ].
- No subscriptions status was specified. The subscription will be created with the status "pending"
- Download permissions cannot be granted because your current WooCommerce settings have disabled this feature.
- Tax line item could not properly be added to this subscription. Please review this subscription.
- Missing tax code or ID from column: [row from CSV]
- The tax code "<tax_code>" could not be found in your store.

A link to edit the subscription is given on the Importer results page.

#### Errors
- The product_id is missing from CSV.
- No product or variation in your store matcges the product ID #.
- An execpected error occurred when trying to add product "<item_name>" to your subscription.
- An error occurred with the customer information provided. Occurs when the user_id provided doesn't exist, the email doesn't exist, the username is invalid.
- The <date_type> date must occur after the <date_type>.
- Coudl not create subscription
- Invalid payment method meta data
- An error occurred when trying to add the shipping item to the subscription
- Fee name is missing from your CSV
- Could not add the fee to your subscription

Any exceptions thrown during the import process will be caught and appear as a fatal error and the subscription will not be imported.

## Subscription Import Options

* **Test Mode** - enabling this option places the import process in a 'Dry Run' mode where no subscriptions or users are created. We strongly suggest running test imports prior to the live import.
* **Email Passwords** - having this option ticked means that when the importer creates a new customer, the customer will receive a registration email containing their login details along with their temporary password.
* **Add memberships** - if you have WooCommerce Memberships active on your site and you are importing products which are attached to your membership plans, you can enable the setting to grant membership access to your imported subscriptions.

### New Customers and Passwords

When the importer doesn't recognise the given customer by the username or email (in the CSV), it will create a new user with those values. 
The minimum requirements for creating a new user is just an email address. If no username is given, the importer will to create a username from the email. Say you you need to create a new user and have only given the email address, janedoe@example.com, the importer will try a new user with username janedoe. If this username is already taken, we then try the username janedoe1, janedoe2 and so on; until it finds a free username (i.e janedoe102). You can specify a password to give the user in the CSV, otherwise, the customer's account is created with the secure password generated by WordPress. This password can be emailed to the customer by selecting the 'Email Password' option at the first step of importing (not selected by default). If left unticked, all your new customers will need to go through the "forgot your password" process which will let them reset their details via email.


## How to use the Importer! :dancer:

### Step 0: Make sure the products you are importing actually exist
 - The minimum requirement for the WooCommerce Subscriptions Importer is that the product in your CSV exists. You can either [create a subscription product](http://docs.woothemes.com/document/subscriptions/store-manager-guide/#section-1) manually if you only have a small number of products, or use the [Product CSV Import Suite](http://www.woothemes.com/products/product-csv-import-suite/) if you need to create a large variety of products.

### Step 1: Upload your CSV file
 - Locate the CSV file by searching for the file on disk.
 - Tick/Untick whether you want to run in test mode before importing to see warnings or fatal errors that have occurred.

### Step 2: Map the CSV fields
 - Each column header found in the CSV will be listed as a row in the table on this page. Use the dropdown menu to find and match the information to a value known by the importer. <strong>You must not have the same fields mapped more than once unless it's found under the custom group.</strong>
 - List of possible fields to map to are [above](#columns)
 - Press Import or Test CSV (depending on whether you are running the import in test mode first)

### Step 3a: Test Mode
If you chose to run the import test mode beforehand, you should see something that looks similar to this.
![Test mode results](https://cldup.com/bFEXxTaBkL.png)
 This table shows all the errors and warnings that occurred while importing without actually creating the new customers and subscriptions. The beauty of running the importer firstly in test mode is that, from here, you can either exit the import process to fix up the CSV or continue importing the file. We strongly recommend you continue to run the importer in test mode until you are satisfied that all the errors are cleared. Unfortunately it's not possible to catch **all** errors when importing your subscriptions. The good news though is that any fatal errors that occurred during the import (i.e. invalid payment method meta) will not create a subscription.

### Step 3b: Import Completion Table
![Completion Table Screenshot](https://i.cloudup.com/VVsB5aBCHf-2000x2000.png)

## FAQ
#### Is it possible to make sure the active subscriptions will still work?
It sure is! When importing active subscriptions, it's important that the correct payment method meta is provided in the CSV. Depending on the payment gateway being used, the information required varies (see below).
  - With __PayPal Reference Transactions__, make sure you have set the `_paypal_subscription_id` field in the `payment_method_post_meta` column. This value needs to be the customers billing agreement (will start with `I-**************`).
  - When using __Stripe__, ensure the `_stripe_customer_id` field is set in the `payment_method_post_meta` column (only values beginning with `cus_*********` will be successful.
  - For those using __Authorize.net__, you will need to provide both the `wc_authorize_net_cim_customer_profile_id` and the `wc_authorize_net_cim_payment_profile_id` in the CSV.

#### Can subscriptions still be imported with an unsupported payment gateway?
Yes but first, it should be understood that they are two possible solutions to consider:

  - defaulting to manual renewals; or
  - force the first payment to fail and allow the customer to fix up their payment option on the failed order (this will fix all future payments)

If you are using a payment gateway that is not supported by the CSV Importer (you can check by following the steps listed [here](), it's important to note that the subscriptions will not successfully import. The first option would be to leave the payment method field empty, and allow the subscription to import using manual renewals.

![Empty Payment Method Field](https://cldup.com/RsNfaSQY8q.png)

The other alternative is to import the subscription with a temporaray payment method (i.e. Stripe) and use valid text as the `_stripe_customer_id` field (a different payment method can be used as long as it is supported by the CSV Importer and supports the changing payment methods feature, see more info on this [here](https://docs.woothemes.com/document/subscriptions/payment-gateways/#advanced-features)). The stripe payment gateway with random text method is used to force the first payment to fail, allowing your customers to login and setup their preferred payment method by clicking the 'Change Payment Method' button on their My Account page and going through the checkout.

If the later approach is taken, we strongly recommend that you notify all affected customers about the change to avoid any confusion in the future.

#### How to check if a payment gateway supports the importer
WooCommerce Subscriptions v2.0 introduced a new hook that allows payment gateways to register their payment method under user or post meta using the filter: `woocommerce_subscription_payment_meta`. The importer utilizes this filter in order to support a wide variety of payment gateways will little to know extra work required. To check if the payment gateway supports the importer, you can search the payment gateways codebase for that filter. If that filter is being used correctly by the payment gateway then there's 99% chance the importer can support it.

An alternative method to checking for importer support is to purchase a test subscription in your store using that payment gateway. Visit the Edit Subscription page of the subscription you just created, and check if you can view and edit the payment meta (see image below). If that is a success, then the importer can 100% import subscriptions using that payment gateway.

![Admin Change Payment Method](https://docs.woothemes.com/wp-content/uploads/2015/09/change-recurring-payment-method-screenshot.png?w=2260)
 
