# WooCommerce Subscriptions Importer and Exporter

[![Build Status](https://travis-ci.org/Prospress/woocommerce-subscriptions-importer-exporter.svg?branch=master)](https://travis-ci.org/Prospress/woocommerce-subscriptions-importer-exporter)
[![codecov](https://codecov.io/gh/Prospress/woocommerce-subscriptions-importer-exporter/branch/master/graph/badge.svg)](https://codecov.io/gh/Prospress/woocommerce-subscriptions-importer-exporter)

Import subscriptions to WooCommerce via CSV, or export your subscriptions from WooCommerce to a CSV.

- [Importer Documentation](#subscriptions-importer)
- [Exporter Documentation](#subscriptions-exporter)

---

# Support

The WooCommerce Subscriptions Importer and Exporter is released freely and openly to help WooCommerce developers migrate subscriber data to [WooCommerce Subscriptions](https://woocommerce.com/products/woocommerce-subscriptions/). Even with this plugin, migrations involve a lot of manual work to format subscription data correctly and test imports. Neither [Prospress](https://prospress.com/) nor [WooCommerce](http://woocommerce.com/) provide services to complete a migration with this tool.

For help with a migration, please contact [WisdmLabs](https://wisdmlabs.com/), an official partner for this extension.

You can learn more about the migration service WisdmLabs offer and contact them via their [WooCommerce Subscriptions Migration page](https://wisdmlabs.com/woocommerce-subscriptions-migration-partner/).

Prospress do not provide support for migration issues. This means Prospress can not help with CSV formatting, fixing broken renewals or other issues with subscriptions imported incorrectly. Similarly, issues with subscriptions created with this plugin are not supported via the WooCommerce support system.

If you think you have found a bug in the extension, problem with the documentation or limitation in the data that can be imported, please [open a new issue to report it](https://github.com/Prospress/woocommerce-subscriptions-importer-exporter/issues/new).

---

# Subscriptions Importer
With the WooCommerce Subscriptions CSV Importer, you can import subscriptions from a CSV file into your WooCommerce store. This is particularly useful when migrating stores from a different eCommerce platform.

The subscriptions will be setup with the [WooCommerce Subscriptions](https://www.woothemes.com/products/woocommerce-subscriptions/) extension to process future recurring payments.

![](https://cldup.com/r53E41w11p.png)

## Before you Begin
Importing your subscriptions to WooCommerce is a complicated process.

To maximize your changes of success with an import, please read through this documentation in its entirety.

There is no substitution for this step. Fully understanding the details of the import process will save you time by avoiding mistakes that may become serious issues in the future.

## Importer Usage Guide

To import your subscriptions to WooCommerce via CSV, you need to:

1. Create a CSV file with all your subscription data formatted for import (this is by far the most complicated step). The [CSV Formatting Guide](#csv-formatting-guide) details the options and requirements for your CSV's content.
1. Go to **WooCommerce > Subscription Importer**
1. Upload your CSV file
1. Select [Import Options](#import-options):
	1. Check **Run in test mode** to validate your CSV file without creating corrupted data
	1. Check **Email Passwords** to email customers that are newly created their account details
	1. Check **Add memberships** to grant subscribers any membership/s plan corresponding to subscription products
1. Click **Upload and file and import**
1. Review each column of data in your file to make sure the Importer has mapped it to the correct column header. 
1. Click **Test CSV**
1. If there are errors, fix up the CSV file and return to step 1.
1. If there are no errors, click **Run Import**
1. Review the [Import Completion Table](#import-completion-table) and [Import Logs](#import-error-logs) for uncover any issues with the import

While these steps may sound simple, the devil is in the details, especially in creating your CSV. The rest of this guide will provide you with the details. Please be sure to **read this in its entirety**.

### Import Options

When uploading your CSV file, the Importer also provides you with a few options to customise the behaviour during the import process.

Import options:
* **Run in Test Mode**
* **Email Passwords**
* **Add Memberships**

![](https://cldup.com/YFwi6NIp-L.png)

#### Run in Test Mode
Running the import in test mode will analyse each row of your CSV and notify you of any [warnings or errors](#list-of-warnings-and-errors) with that data. 

It will not import any subscription data in your store's database, like users or subscriptions.

After running test mode, the Importer will provide you with an **Importer Test Results** table. This displays the issues with your CSV. If there are issues, you can safely abort the import process to fix up the CSV. If there are no issues, you can continue the Import.

We strongly recommend you continue to run the importer in test mode until you have correct all warnings and errors. Unfortunately it's not possible to catch *all* errors that will occur when actually importing your subscriptions. The good news though is that even during an actual import, exceptions and other errors that can only be discovered at the time of import will be logged and the corresponding subscription will not be created.

![**Example Importer Test Results**](https://cldup.com/gfJUvR7WIe-2000x2000.png)

#### Email Passwords

When a CSV files includes a username or email address, and the Importer can not find an existing user with either of those details, it will create a new [WordPress user](https://codex.wordpress.org/Users_Screen).

You can also specify a password to use on the user's account in the CSV, otherwise, the account is created with the secure password generated by WordPress.

When the **Email Passwords** option is enabled, if the Importer creates a new user account, it will also email that user a registration email with their login details and a temporary password.

If left unticked, the new users created  will need to go through the "forgot your password" process which will let them reset their details via email.

Please note: the minimum requirement for creating a new user is an email address. If no username is given, the importer will to create a username from the email. Say you you need to create a new user and have only given the email address, janedoe@example.com, the importer will try a new user with username janedoe. If this username is already taken, we then try the username janedoe1, janedoe2 and so on; until it finds a free username (i.e janedoe102). 

#### Add Memberships

If you have [WooCommerce Memberships](https://www.woothemes.com/products/woocommerce-memberships/) active on your site, you will also be presented with the option to **Add Memberships**.

When this option is enabled, subscriptions imported with product line items linked to a [membership plan](https://docs.woothemes.com/document/woocommerce-memberships-plans/) will also grant the subscriber's user account with corresponding membership access.

### Column Mapping

During step 2 of the import process, each column header found in the CSV will be listed as a row in the table on the *Map Fields to Column Names* page.

You can use the dropdown menu to find and match each piece of data to a value known by the importer. **You must not have the same fields mapped more than once unless it's found under the custom group.**

The full list of fields, with an explanation of each, are available in the [CSV Columns](#csv-columns) section of this guide.

### Import Completion Table
After an import has been completed, you will be provided with a table displaying the result of each row's import.

This table will display the success of failure of the import for each row, as well as any [warnings or errors](#list-of-warnings-and-errors) that occurred when importing that row.

Review this table carefully after running your import to identify any potential issues with each subscription.

![Import Completion Table Screenshot](https://cldup.com/ALx-5YbHHB-2000x2000.png)

### Import Error Logs
In addition to the output displayed in the [Import Completion Table](#import-completion-table), the Subscriptions CSV Importer will also log issues into a special log file.

These log entries can be reviewed to see what when wrong during the import and therefore, what needs to be fixed up after the import (if anything).

These logs may contain issues not identified during [test mode](#run-in-test-mode) which relate to plugin conflicts, database issues or other errors that only occur during the live import. Becuase of this, we strongly encourage you to review the log files even if your CSV had no issues during test mode.

#### Exception Logger
The importer will catch all [PHP exceptions](http://php.net/manual/en/language.exceptions.php) thrown during the import and log them along with with the line in the CSV that was being processed when the exception occurred.

To view the exception logs:

1. Go to the **WooCommerce > System Status** administraction screen
1. Click the **Logs** tab
1. Click the select box for log files
1. Click file prefixed with: _wcs-importer-_
1. Click _View_

![Import Logs](https://cldup.com/uyUAB1Ssuq.png)

##### Example Exception Log Entry

```
01-15-2016 @ 05:30:26 - Row #2 failed: Array (
    [0] => Could not create subscription: Invalid Subscription billing period given.
)
```

#### Fatal Error Logger
When a fatal error occurs during the import process, the importer will log the row of data from the CSV that caused the issue and a stack trace of where the error occurred.

To view the shutdown logs:

1. Go to the **WooCommerce > System Status** administraction screen
1. Click the **Logs** tab
1. Click the select box for log files
1. Click file prefixed with: _wcs-importer-shutdown-_
1. Click _View_

![Import Shutdown Logs](https://cldup.com/rz7dwYivWN-2000x2000.png)

##### Example Fatal Error Log Entry

```
01-12-2016 @ 05:52:16 - CSV Row: Array
(
    [product_id] => 5078
    [status] => cancelled
    [customer_id] => 1
    [start_date] =>
    [next_payment_date] =>
    [end_date] =>
    [shipping_method] => free_shipping
    [coupon_items] => code:recurring_discount|amount:10.00
)

01-12-2016 @ 05:52:16 - PHP Fatal error Call to undefined method WCS_Importer::add_coupons() in /Users/Matt/Dropbox/Sites/subs2.0/wp-content/plugins/woocommerce-subscriptions-importer-exporter/includes/class-wcs-import-parser.php on line 425.
```

## CSV Formatting Guide
By far the most difficult aspect of migrating your subscriptions using the CSV Importer is formatting a valid CSV with all required data.

A subscription is created from a variety of different pieces of data, from customer details like billing and shipping address, to product details, like quantity, taxes and totals, shipping details, like shipping method, tax and cost, as well as billing schedule details, like recurring billing period, next payment date and end date. To import your subscriptions, you must create a CSV with all of this data in a valid form.

This section provides you with an overview of all the fields your CSV can contain (and in some cases, must contain).

### CSV Requirements

Please follow these general rules when formatting your CSV file:
* The first row must include column headers - this is so the plugin knows where to map the fields.
* Each subscription needs to have its own row.
* Not all columns need to be filled in for each row, unless the field is labelled _required_ in the [CSV Columns](#csv-columns) section.
* Most fields are not required. However, to avoid creating subscriptions with unintended default data applied, specify as many fields as possible in your CSV.
* Two-letter country and state/county abbreviations should be used for all country, state or county data.
* Date values should always be in UTC/GMT timezone.
* Date fields can be in any string format handled by PHP's `strtotime()` function. However, `strtotime()` is a strange beast that on occasion creates unexpected date strings. It also doesn't always handle timezones as expected. Becuase of this, we encourage you to always use MySQL format for dates, e.g. `YYYY-MM-DD HH:MM:SS` or in [PHP's date formatting terms](http://php.net/manual/en/function.date.php) `y-m-d H:i:s`). For example, `1984-01-22 17:45:13`.
* All dollar amounts can be either an integer or decima/float value. For example, `5.65`, `3` and `127.2` are all valid.
* Arrays are formated as `key:value|key:value|key:value`.

### CSV Columns

|Column Name|Format|Description|Default|
|---|---|---|---|---|
|`customer_id`|`int`|Can be left blank to creating a new user or if username and/or email of an existing user is present.|-|
|`customer_email`|`string`|The email of the user to assign this subscription to. If no `customer_id` or `customer_username` is specified, a new user will be created with this email.|-|
|`customer_username`|`string`|The username of the user to assign this subscription to, if any.|-|
|`customer_password`|`string`|A password to set on the user's account, if creating a new user.|If left blank and creating a new user, a password will be automatically generated.|
|`billing_first_name`|`string`|The first name to use on the billing address for the subscription and renewal orders.|The value stored on the user's account (in user meta) will be used, if the user exists and this is set, otherwise, it will be set as an empty string (i.e. `''`).|
|`billing_last_name`|`string`|The last name to use on the billing address for the subscription and renewal orders.|The value stored on the user's account (in user meta) will be used, if the user exists and this is set, otherwise, it will be set as an empty string (i.e. `''`).|
|`billing_address_1`|`string`|The street address to use on the billing address for the subscription and renewal orders.|The value stored on the user's account (in user meta) will be used, if the user exists and this is set, otherwise, it will be set as an empty string (i.e. `''`).|
|`billing_address_2`|`string`|The street address to use on the billing address for the subscription and renewal orders.|The value stored on the user's account (in user meta) will be used, if the user exists and this is set, otherwise, it will be set as an empty string (i.e. `''`).|
|`billing_city`|`string`|The city to use on the billing address for the subscription and renewal orders.|The value stored on the user's account (in user meta) will be used, if the user exists and this is set, otherwise, it will be set as an empty string (i.e. `''`).|
|`billing_state`|`string`|The state or county to use on the billing address for the subscription and renewal orders.|The value stored on the user's account (in user meta) will be used, if the user exists and this is set, otherwise, it will be set as an empty string (i.e. `''`).|
|`billing_postcode`|`string`|The postal or zip code to use on the billing address for the subscription and renewal orders.|The value stored on the user's account (in user meta) will be used, if the user exists and this is set, otherwise, it will be set as an empty string (i.e. `''`).|
|`billing_country`|`string`|The 2 letter country code to use on the billing address for the subscription and renewal orders.|The value stored on the user's account (in user meta) will be used, if the user exists and this is set, otherwise, it will be set as an empty string (i.e. `''`).|
|`billing_email`|`string`|The email address to use on the billing address for the subscription and renewal orders.|The value stored on the user's account (in user meta) will be used, if the user exists and this is set. If that is not set, the `'customer_email'` value of the CSV will be used, if set. Finally, if `'customer_email'` is not set in the CSV, the `'user_email'` set on the user's account will be used.|
|`billing_phone`|`string`|The phone number to use on the billing address for the subscription and renewal orders.|The value stored on the user's account (in user meta) will be used, if the user exists and this is set, otherwise, it will be set as an empty string (i.e. `''`).|
|`billing_company`|`string`|The name of the company to use on the billing address for the subscription and renewal orders.|The value stored on the user's account (in user meta) will be used, if the user exists and this is set, otherwise, it will be set as an empty string (i.e. `''`).|
|`shipping_first_name`|`string`|The first name to use on the shipping address for the subscription and renewal orders.|The value stored on the user's account (in user meta) will be used, if the user exists and this is set, otherwise, it will be set as an empty string (i.e. `''`).|
|`shipping_last_name`|`string`|The last name to use on the shipping address for the subscription and renewal orders.|The value stored on the user's account (in user meta) will be used, if the user exists and this is set, otherwise, it will be set as an empty string (i.e. `''`).|
|`shipping_address_1`|`string`|The street address to use on the shipping address for the subscription and renewal orders.|The value stored on the user's account (in user meta) will be used, if the user exists and this is set, otherwise, it will be set as an empty string (i.e. `''`).|
|`shipping_address_2`|`string`|The street address to use on the shipping address for the subscription and renewal orders.|The value stored on the user's account (in user meta) will be used, if the user exists and this is set, otherwise, it will be set as an empty string (i.e. `''`).|
|`shipping_city`|`string`|The city to use on the shipping address for the subscription and renewal orders.|The value stored on the user's account (in user meta) will be used, if the user exists and this is set, otherwise, it will be set as an empty string (i.e. `''`).|
|`shipping_state`|`string`|The state or county to use on the shipping address for the subscription and renewal orders.|The value stored on the user's account (in user meta) will be used, if the user exists and this is set, otherwise, it will be set as an empty string (i.e. `''`).|
|`shipping_postcode`|`string`|The postal or zip code to use on the shipping address for the subscription and renewal orders.|The value stored on the user's account (in user meta) will be used, if the user exists and this is set, otherwise, it will be set as an empty string (i.e. `''`).|
|`shipping_country`|`string`|The 2 letter country code to use on the shipping address for the subscription and renewal orders.|The value stored on the user's account (in user meta) will be used, if the user exists and this is set, otherwise, it will be set as an empty string (i.e. `''`).|
|`subscription_status`|`string`|The status to apply to the subscription after it is created. Can be one of: `wc-active`, `wc-expired`, `wc-pending`, `wc-on-hold`, `wc-pending-cancel` or `wc-cancelled`. Although the `wc-` prefix is recommended, it is not required.|`wc-pending`|
|`start_date`|`Y-m-d H:i:s`|The start time to set on the subscription. Must be in the past.|The current time.|
|`trial_end_date`|`Y-m-d H:i:s`|A date in the past or future on which a the subscriptions trial period will end. If set, the trial end date must come after the start date.|-|
|`next_payment_date`|`Y-m-d H:i:s`|The date to process the next renewal payment. If set, the next payment date must come after the start date and trial end date and be in the future. If left empty, when the status is next updated to `wc-active` the next payment date will be calculated based on the start or trial end date and billing period/interval.|-|
|`end_date`|`Y-m-d H:i:s`|The date on which the subscription will expire, if in the future, or was cancelled or expired, if in the past. Leave empty to have the subscription continue to renew until manually cancelled.|-|
|`billing_period`|`string`|The time period used for calculating renewal payment dates. Must be either: `day`, `week`, `month`, `year`. An invalid or empty billing period will cause an error during the import and the subscription will not be imported.|-|
|`billing_interval`|`int`|The interval used for calculating renewal payment dates. Must be an integer value to represent how many subscription periods between each payment. For example, a `2` here and `week` for the `billing_period` will create a subscription processes a renewal payment every two weeks.|`1`|
|`order_items`|`mixed`|The product line items on the subscription used to set the line items on renewal orders. Can be a product or variation ID or a more advanced set of data as detailed in the [Importing Order Items](#importing-order-items-product-line-items) section.|-|
|`coupon_items`|`array`|Add coupon line items to the subscription and renewal orders. Refer to the guide on [Importing Subscriptions with Coupons](#importing-subscriptions-with-coupons) for details.|-|
|`fee_items`|`array`|Add fee line items to the subscription and renewal orders. Refer to the guide on [Importing Subscriptions with Fee Line Items](#importing-subscriptions-with-fee-line-items) for details.|-|
|`tax_items`|`mixed`|Add tax line items to the subscription and renewal orders. Refer to the guide on [Importing Subscriptions with Tax Line Items](#importing-subscriptions-with-tax-line-items) for details.|-|
|`cart_discount`|`float`|The total discount amount to set on the subscription. Displayed on the subscription and each renewal order.|`0`|
|`cart_discount_tax`|`float`|The total tax amount included in the total discount amount.|`0`|
|`order_shipping`|`float`|The total shipping amount to set on the subscription. Displayed on the subscription and each renewal order.|`0`|
|`order_shipping_tax`|`float`|The total tax amount included in the total shipping amount.|`0`|
|`order_total`|`float`|The total amount to charge for each renewal payment. Displayed on the subscription and each renewal order.|`0`|
|`order_tax`|`float`|The total tax amount to be charged with each renewal payment.|`0`|
|`order_currency`|`string`|A three character currency code (e.g. `USD`). Represents the currency in which renewal payments should be processed.|[Store's currency](https://docs.woothemes.com/document/shop-currency/).|
|`shipping_method`|`mixed`|This can be either the shipping method ID as seen in the table at **WooCommerce > Settings > Shipping** page, i.e. `"free_shipping"` or `"flat_rate"`, or this can be an in the format `"shipping_id:flat_rate:|shipping_title:Flat Rate|total:10.00"`.|-|
|`download_permissions`|`int`|Can be either `true` / `1` to grant download permissions for product line items on the subscription, or `false` / `0` to not grant download permissions.|`false`|
|`order_notes`|`array`|A string of order notes separated by the `;` symbol. For example `"Payment received.;Subscription activated."` will create two order notes on your subscription.|-|
|`payment_method`|`string`|Set as the Gateway ID which can be seen in table at **WooCommerce > Settings > Checkout**. Leave blank for [manual renewals](http://docs.woothemes.com/document/subscriptions/renewal-process/).|-|
|`payment_method_title`|`string`|The name of the payment gateway to display to the customer when viewing the subscription or related orders (e.g. "Credit Card")|The value of `payment_method`, if set, else `''`.|
|`payment_method_post_meta`|`array`|Payment gateway meta data required to process automatic recurring payments. See [Importing Payment Gateway Meta Data](#importing-payment-gateway-meta-data) for more information.|-|
|`payment_method_user_meta`|`array`|Payment gateway meta data required to process automatic recurring payments. See [Importing Payment Gateway Meta Data](#importing-payment-gateway-meta-data) for more information.|-|
|`customer_note`|`string`|An optional to include on the subscription from the customer that is shared with for store managers.|-|
|`custom_user_meta`|`mixed`|A column to specify arbitrary data to store against on the subscriber in the user meta table. Multiple columns can be mapped to this header. The value of the column header in the CSV will be used as the meta key. For example, if you want to add `'_my_meta_key' => true` as user meta. You will need to have a column in your CSV with header `_my_meta_key` and map it to `custom_user_meta`. Custom user meta is added to the user _before_ the subscription is created.|-|
|`custom_post_meta`|`mixed`|A column to specify arbitrary data to store against on the subscription in the post meta table. Multiple columns can be mapped to this header. The value of the column header in the CSV will be used as the meta key. For example, if you want to add `'_my_meta_key' => true` as post meta. You will need to have a column in your CSV with header `_my_meta_key` and map it to `custom_post_meta`. Custom post meta is added after the subscription is created.|-|
|`custom_user_post_meta`|`mixed`|Use this column if you wish to add custom data to both user and post meta using the same `meta_key` and value. Like previously stated, the value of the column header in the CSV will be used as the meta_key value.|-|

If any of the above columns contains invalid data, the importer will display these during in the test run. If you choose to ignore the errors and continue to import with invalid data, no subscription will be imported for that row.

### Importing Payment Gateway Meta Data
You can import payment gateway meta data, like customer or credit card tokens, in your CSV file to link a subscription with a payment method for processing [recurring payments automatically](https://docs.woothemes.com/document/subscriptions/renewal-process/).

Properly importing payment gateway meta data is a difficult, yet crucial part of the import. It's important to take the time and get this right otherwise your subscription may not renew properly. It is also much faster to set this data in bulk on import than to set it on each individual subscription after the import.

#### Supported Payment Gateways

The Importer can support any payment gateway that supports the new  `woocommerce_subscription_payment_meta` filter introduced in WooCommerce Subscriptions v2.0.

Each payment method requires different meta data to process automatic payments. Because of this, we are not able to provide documentation on what meta data is required for every possible [payment gateway](https://docs.woothemes.com/document/subscriptions/payment-gateways/).

However, here is a list of the column headers and meta data description for three popular payment methods:
 * __PayPal Reference Transactions__: `_paypal_subscription_id` must be mapped to `payment_method_post_meta` column. This value needs to be the customers billing agreement (will start with `I-**************`).
 * __Authorize.net CIM__: `_wc_authorize_net_cim_credit_card_customer_id` and `_wc_authorize_net_cim_credit_card_payment_token` mapped to `payment_method_post_meta` column.
 * __Stripe__: `_stripe_customer_id` mapped to `payment_method_post_meta` column and optionally, `_stripe_card_id` also mapped to `payment_method_post_meta` column if you want to charge recurring payments against a specific payment method on the customer's account. Only values beginning with `cus_` and `card_` will be considered valid tokens.

> Note: the above information relates to the official [Stripe](https://www.woothemes.com/products/stripe/) and [Authorize.net CIM](https://www.woothemes.com/products/authorize-net-cim/) extensions. It will not work with other extensions for those payment gateways.

As long as the payment gateway extension for your payment gateway is active, the Subscriptions CSV Importer will validate that you have included the necessary payment gateway meta data.

For example, if you try to import a subscription with `stripe` set as the `payment_method`, but are missing the `_stripe_customer_id`, the subscription will not be created and the import will fail with a message explaining that a valid `_stripe_customer_id` is required.

If you need to import subscriptions using a payment gateway other than those above, please ask the gateway's extension developer for details of the post or user meta data required to process automatic payments. If you also let us know which meta data is required, we will include it in this documentation to help others in future.

### Importing Order Items

In WooCommerce, orders can have a number of different line items, including:

* product line items
* shipping line items
* fee line items

A subscription is a custom order type, and therefore, can also have each of these line items added to it.

#### Importing Product Line Items

The `order_items` column can be either:

* a **Single Product ID** for the product you want the set as the product line item on the subscription; or
* an array of **Line Item Data**, including line item totals and tax amounts.

##### Single Product ID

When using just a single product ID in the `order_items` column, all line item totals and subtotals will default to the product's price at the time of import.

No amount will be added for tax, regardless of the store's tax settings.

**Note:** to import a variation of a variable prodcut, you must use the variation's ID, not the parent variable product's ID.

##### Line Item Data
To add tax or other custom information to your product line items you need to follow strict formatting for it to properly import. Each product line item needs to follow the following format: `product_id:5179|quantity:1|total:9.09|tax:0.91`.

The table below provides a full list of line item data that can be used when importing line items.

| Key | Type | Default | Description |
|---|---|---|---|
|`product_id`|`int`|-|**Required**. Must be either a product or variation ID of an existing product in your store. This does not specifically need to be a subscription product.|
|`name`|`string`|The product's current title.|A custom name to use for the product line item instead of the product's current title on the store.|
|`quantity`|`int`|`1`|The number of this line item to include on each renewal order.|
|`subtotal_tax`|`float`|`0`|The line tax total before pre-tax discounts.|
|`subtotal`|`float`|Value of `total`|The line total before pre-tax discounts.|
|`tax`|`float`|`0`|The line tax total after pre-tax discounts.|
|`total`|`float`|Product's price|The line total after pre-tax discounts.|
|`meta`|`string`|Product's variation data, if any.|Line item meta data to store against this product line item, see the section below on [Line Item Meta Data](#line-item-meta-data) for detals.|

An example `order_items` column content to import a product line item would look something like this:

```
product_id:123|name:Product to Import|quantity:3|subtotal_tax:3.00|subtotal:30.00|tax:3.00|total:30.00|meta:size=Large+shirt-colour=Midnight Black
```

###### Line Item Meta Data
When importing a product line item, by default, the Importer will set the variation attributes (if any) of the product with a matching ID for the imported line item as line item meta data.

If you would prefer to set different attributes, or need to import meta data that does not originate from the variation attributes, you can use the `meta` field in the `order_items` colum.

The `meta` field can include one or more piece of meta data and must be formatted using `=` to delimit the meta key and value, and `+` to delimit each piece of meta data.

For example, to import a product line item with an _Size_ and _Shirt Colour_ meta with _Large_ and _Midnight Black_ values respectivately, the meta field would look like: `meta:size=Large+shirt-colour=Midnight Black`.

Notice the _Shirt Colour_ meta key is in lowercase and uses a `-` instead of a space (i.e. `' '`) while the _Midnight Black_ is capitalised with a space. The meta keys in your `meta` field should be raw values to store in the database, not the formatted value returned by `WC_Order_Item_Meta::display()` or `WC_Order_Item_Meta::get_formatted()`.

##### Multiple Product Line Items
To import a subscription with multiple product line items, separate each line item data with a `;`. You can use a combination of the single product ID method and full line item data array method.

For example, an `order_items` column value for two products could look like: `"product_id:5179|quantity:2|total:9.09|tax:0.91;product_id:2156|total:30"`. See the [Sample CSV](https://github.com/Prospress/woocommerce-subscriptions-importer-exporter/blob/master/wcs-import-sample.csv) for an example of importing multiple product line items using the importer.

#### Importing Subscriptions with Coupons
The importer provides the `coupon_items` column header to apply coupons to your imported subscriptions.

You can only apply valid coupon codes that exist in your store at the time of the import. If you attempt to use a coupon code that doesn't exist, you will see the error `Could not find coupon with code "<your_code>" in your store.` and that row in your CSV will not be imported.

When formatting your `coupon_items` row, use the array syntax, for example: `"code:summerdiscount2016|amount:15.00"`. You can attach multiple coupons/discounts to your imported subscription by separating each coupon item by the `;` character. For example: `"code:summerdiscount2016|amount:15.00;code:earlybird|amount:5.00"`.

| Key | Type | Default | Description |
|---|---|---|---|
|`code`|`string`|-|(**Required**) A coupon code which exists in your store at time of import.|
|`amount`|`float`|Discount amount set on the coupon.|The discount amount to apply to the subscription.|

#### Importing Subscriptions with Fee Line Items
The importer provides the `fee_items` column header to add fee line items to your imported subscriptions.

To attach fees to your imported subscriptions, the minimum requirement is the fee `name` field.

An example of the `fee_items` column could look like: `"name:Handling|total:7.00|tax:0.70"`.

You can attach multiple fees to an imported subscription by separating the fee items with the `;` symbol.

| Key | Type | Default | Description |
|---|---|---|---|
|`name`|`string`|-|(**Required**) The name to use on the fee line item. If this is empty, the subscription will not be imported and you will receive an error.|
|`total`|`float`|`0`|The amount to charge for the fee.|
|`tax`|`float`|`0`|The tax to charge for the fee.|

#### Importing Subscriptions with Tax Line Items
The importer provides the `tax_items` column header to add tax line items to your imported subscriptions.

If you're importing one tax rate, you can use a valid tax ID (`int`) or tax code (e.g. `VAT`) from your WooCommerce store in the `tax_items` column. If need to include more than one tax line item, you can use the more complicated array format.

The minimum requirement for attaching tax items is to have either the ID or the tax code field. An example `tax_items` column would look like: `code:VAT` or `id:3`.

| Key | Type | Default | Description |
|---|---|---|---|
|id|`int`|-|The unique tax rate ID found in DB.|
|code|`string`|-|The tax rate label|

There's a few simplifications when it comes to how the importer manages taxes. If you only provide the tax code in your CSV, the importer will query your available taxes and choose the tax with that code with the highest priority (i.e. if you have multiple taxes codes with VAT then it's possible the incorrect tax rate may be attached to your subscription). The alternative and more accurate workaround is to import tax items using the unique tax rate ID (found in `{$wpdb->prefix}woocommerce_tax_rates` table under column `tax_rate_id`).

When the tax rate is successfully added to your subscription, this rate is then used as the tax class for all tax values for things like line items, shipping lines, fees etc.

### Sample CSV

A [Sample CSV](https://github.com/Prospress/woocommerce-subscriptions-importer-exporter/blob/master/wcs-import-sample.csv) file is included in the Importer's folder with the file name `wcs-import-sample.csv`.

This CSV includes a number of rows to provide examples for the many different acceptable values for CSV column content. It uses almost all the available CSV column headers (though not all rows fill in all columns).

The CSV file also includes a column headed `Row Description (Do not import)` which provides a brief description of the example that row provides. However, in addition to the specific feature mentioned in that column, each row also provides examples of many different types of column values, like `order_notes`, `customer_notes`, totals, line items and download permissions. It also provides a good demonstrate of how to format complex data, like multiple `order_items` with custom meta data associated with them.

You can use the CSV on a test site to see the Importer in action. To use the CSV you need to first:

* make sure a customer exists with user ID `1` (for the first row of the CSV which creates a subscription based on user ID)
* update the `product_id:1` and `product_id:2` values in the `order_items` column to link to use the post IDs of real products

Because the Sample CSV file uses almost all the available CSV columns, it is a great place to start in creating your own CSV file.

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

When a warning is included in the [Import Completion Table](#import-completion-table), a link to [edit the subscription](https://docs.woothemes.com/document/subscriptions/add-or-modify-a-subscription/) administration screen is provided alongside the subscription.

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

## Importer FAQ
#### Is it possible to make sure the active subscriptions will still process automatic payments?
Yes. If your subscriptions payment gateway supports [automatic recurring payments](http://docs.woothemes.com/document/subscriptions/payment-gateways/), it may also be able to have its [payment method meta data](#importing-payment-gateway-meta-data) imported to link the subscription to the payment gateway to process future automatic recurring payments.

When importing active subscriptions, it's important that the correct [payment method meta data](#importing-payment-gateway-meta-data) is provided in the CSV. Depending on the payment gateway being used, the information required varies. For details, see the section on [Importing Payment Gateway Meta Data](#importing-payment-gateway-meta-data).

#### Can subscriptions using PayPal Standard be imported?
No. Due to [PayPal Standard limitations](https://docs.woothemes.com/document/subscriptions/payment-gateways/#paypal-limitations), the importer can not migrate subscriptions using PayPal Standard as the payment method.

Note that the same limitations do not apply to [PayPal Reference Transactions](https://docs.woothemes.com/document/subscriptions/faq/paypal-reference-transactions/). Therefore the CSV importer can migrate subscriptions which use a PayPal Billing Agreement to process recurring payments via Reference Transactions.

If you have subscriptions with PayPal Standard and you're interested in getting your customers to use a different payment method, you can import the subscriptions and request that your customers [change the payment method](https://docs.woothemes.com/document/subscriptions/customers-view/#section-5) on those subscriptions.

#### Can subscriptions still be imported with a payment gateway that doesn't support migrating automatic payment meta data?
Yes. There are two possible options:

* switch the subscriptions to use [manual renewals](http://docs.woothemes.com/document/subscriptions/renewal-process/); or
* force the first payment to fail and allow the customer to [pay for renewal](https://docs.woothemes.com/document/subscriptions/customers-view/#section-3), which will update their payment details for future payments

To switch the subscriptions to use manual renewal, you can leave the `payment_method` column empty.

![Empty Payment Method Field](https://cldup.com/RsNfaSQY8q.png)

To import the subscription in a way that the first automatic payment fails, you need to:

1. import using a temporary, valid payment method (e.g. `stripe`)
1. set a valid column value for the [payment method meta data](#importing-payment-gateway-meta-data) (e.g. set `_stripe_customer_id` to `cus_12345`)

When Subscriptions attempts to process the next renewal payment, the transaction will fail and Subscriptions [failed payment handling](https://docs.woothemes.com/document/subscriptions/renewal-process/#section-6) process will begin.

If the later approach is taken, we strongly recommend that you notify customers about the change to avoid confusion.

#### How can I check if a payment method can be imported with automatic payments?
WooCommerce Subscriptions v2.0 introduced a new way for payment gateways to register the payment meta data they require for processing automatic recurring payments. 

To support this method, the payment gateway extension must use the filter: `'woocommerce_subscription_payment_meta'`.

The importer also uses this filter in order to support a number of payment gateways with no extra code required in the gateway.

To check if subscriptions using a specific payment gateway can be imported with automatic recurring payments, you can search the payment gateway extension's codebase for the `'woocommerce_subscription_payment_meta'` filter.

If that filter is being used correctly by the payment gateway then it should also work with the importer.

An alternative method to checking for importer support is to purchase a test subscription in your store using that payment gateway. Visit the **WooCommerce > Edit Subscription** page of the subscription you just created, and check if you can view and edit the payment method meta (see image below). If you can see these fields, the importer can import subscriptions using that payment gateway and process automatic recurring payments.

![Admin Change Payment Method](https://docs.woothemes.com/wp-content/uploads/2015/09/change-recurring-payment-method-screenshot.png?w=2260)

#### Why aren't my product line item attribute keys capitalised?
WooCommerce displays product line item attribute keys based on either:

* the attribute label in the store, if set; or
* the string key, if no matching attribute can be found.

If you have imported subscriptions with line items that have product attributes, you should also create matching [product attributes](https://docs.woothemes.com/document/managing-product-taxonomies/#section-3) in the new store.

#### Why aren't taxes showing on my fee line items?
Although the Importer provides a way to import tax data about fee line item, unfortunately, WooCommerce only displays tax line item data based on an array of tax data linked to the tax rate IDs. Becuase these are not imported, fee taxes will not be displayed on the on the **WooCommerce > Edit Subscription** and **WooCommerce > Edit Order** screens.

---

# Subscriptions Exporter
With the Subscriptions CSV Exporter, you can download a comma delimited (CSV) file that contains all the details of the subscriptions in your WooCommerce store.

You can optionally filter the exported data to export only subscriptions with a specific:

* status, like _active_ or _on-hold_
* recurring payment method, like PayPal or Stripe
* customer set as the subscriber

You can also choose which data to include in the CSV file.

![](https://cldup.com/WK-9aRHQ7r.png)

Please note: this extension will not export any related orders for your subscriptions (this includes parent and renewal orders, etc); to export orders, you will need the [WooCommerce Order CSV Exporter](https://www.woothemes.com/products/ordercustomer-csv-export/).

## Exporter Usage Guide

1. Go to the **WooCommerce > Subscription Exporter** administration screen
1. On the **Export Tab**:
	1. Enter a name for the file (or leave as the default)
	1. Choose the status to filter exported subscriptions by, or leave all status checked to export subscriptions with any status
	1. Click the *Export for Customer* select box and use the search to find a customer via name, username, email or ID and then export only subscriptions for that customer
	1. Click the *Payment Method* select box and choose a specific payment method to export only subscriptions using a certain payment method
	1. Click the *Payment Method Tokens* checkbox if you want to include payment meta data, like customer or credit card tokens, in your CSV file
1. Click the **CSV Headers** tab
1. Click the radio fields under the **Include** column if you wish to exclude any subscription data from the CSV
1. Change the text in the **CSV Column Header** column to change the string used to identify each piece of subscription data
1. Click **Export Subscriptions**

### Export Options

1. **File name**: the name used for the CSV file (defaults to `subscriptions.csv`).
2. **Subscription Status**: the subscriptions that are exported by status - untick any statuses you don't want exported (defaults to all statuses).
3. **Customer**: filter subscriptions that belong to a speific customer.
4. **Payment Method**: Use the dropdown to export subscriptions that were purchased with the chosen gateway (defaults to any gateway)
5. **Payment Method Tokens**: Select whether you want payment method tokens, like a customer or credit card token, to be exported in the CSV (defaults to false)

![](https://cldup.com/P9e5V-xjGM.png)

### Custom CSV Headers
Before exporting, you have the option to modify the column names which are written to the CSV along with choosen which column headers are exported. For instance, you can choose to just export the customer's billing first and last name, along with the subscriptions order total.

![](https://cldup.com/o2aw3IBCsa.png)

## Exported CSV Columns
|column|type|description|
|---|---|---|
|`subscription_id`        |`int`|Subscription ID|
|`subscription_status`    |`string`|Subscription Status (i.e. `wc-active`, `wc-on-hold`)|
`customer_id`             |`int`|Customer ID|
`start_date`              |Y-m-d H:i:s|Subscription start date|
`trial_end_date`          |Y-m-d H:i:s|Subscription trial end date (defaults to 0 when the subscription has no trial period|
`next_payment_date`       |Y-m-d H:i:s|Subscription next payment date|
`last_payment_date`       |Y-m-d H:i:s|Subscription last payment date|
`end_date`                |Y-m-d H:i:s|Subscription end date (defaults to 0 when the subscription has no end)|
`billing_period`          |`string`|Billing period|
`billing_interval`        |`int`|Billing interval|
`order_shipping`          |`float`|Total shipping|
`order_shipping_tax`      |`float`|Total shipping tax|
`fee_total`               |`float`|Total subscription fees|
`fee_tax_total`           |`float`|Total fees tax|
`order_tax`               |`float`|Subscription total tax|
`cart_discount`           |`float`|Cart discount|
`cart_discount_tax`       |`float`|Total discount|
`order_total`             |`float`|Total discount tax|
`order_currency`          |`string`|Subscription currency|
`payment_method`          |`string`|Payment method id|
`payment_method_title`    |`string`|Payment method title|
`payment_method_post_meta`|`string`|Payment method post meta|
`payment_method_user_meta`|`string`|Payment method user meta|
`shipping_method`         |`string`|Shipping method|
`billing_first_name`      |`string`|Billing first name|
`billing_last_name`       |`string`|Billing last name|
`billing_email`           |`string`|Billing email|
`billing_phone`           |`string`|Billing phone|
`billing_address_1`       |`string`|Billing address 1|
`billing_address_2`       |`string`|Billing address 2|
`billing_postcode`        |`string`|Billing postcode|
`billing_city`            |`string`|Billing city|
`billing_state`           |`string`|Billing state|
`billing_country`         |`string`|Billing country|
`billing_company`         |`string`|Billing company|
`shipping_first_name`     |`string`|Shipping first name|
`shipping_last_name`      |`string`|Shipping last name|
`shipping_address_1`      |`string`|Shipping address 1|
`shipping_address_2`      |`string`|Shipping address 2|
`shipping_postcode`       |`string`|Shipping post code|
`shipping_city`           |`string`|Shipping city|
`shipping_state`          |`string`|Shipping state|
`shipping_country`        |`string`|Shipping country|
`shipping_company`        |`string`|Shipping company|
`customer_note`           |`string`|Customer note|
`order_items`             |`string`|Subscription Items|
`order_notes`             |`string`|Subscription order notes|
`coupon_items`            |`string`|Coupons|
`fee_items`               |`string`|Fees|
`tax_items`               |`string`|Taxes|
`download_permissions`    |`int`|Download permissions granted (1 or 0)|


---

<p align="center">
<img src="https://cloud.githubusercontent.com/assets/235523/11986380/bb6a0958-a983-11e5-8e9b-b9781d37c64a.png" width="160">
</p>
