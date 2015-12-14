# WooCommerce Subscriptions Importer

The WooCommerce Subscriptions CSV Import Suite allows you to easily import your subscriptions from a CSV file straight into your WooCommerce Store. This is particularly useful when migrating stores and importing from a different eCommerce platform - which may or may not use exactly the same fields as WooCommerce.

## Before Installing - know payPal standard limitations!
Please note that it is not possible to move subscriptions created with PayPal Standard to a store using a different URL to the store where the customer initially signed up for the subscription. This is not a limitation in WooCommerce Subscriptions or the importer, this is a limitation with PayPal Standard and its IPN system. Note that these limitations do not apply to PayPal Reference Transactions.

If you're interested in getting your customers to use a different payment method, then please try using the Failed Payment approach explained in our documentation here: http://docs.woothemes.com/document/subscriptions/customers-view/#section-3

## Importer guide - must read!
The following list of points can be taken to maximize success and minimize time and problems that occur while using this plugin:

1.  Read through this entire documentation so you know how the import process operates and its requirements. There is no substitution for this step; yes reading documentation is a pain and takes time, but it will actually save you time in the end.
2.  Make sure the product_id in the CSV exists as a regular subscription or variable subscription.
3.  Set the column names and data formats for the files as described below in this documentation. This is not a requirement however if the columns in your CSV are named the same as described below, they will automatically be selected - saving you time.
4.  Before importing, run the importer in test mode first to avoid unexpected outcomes, test mode can be enabled by checking the box displayed on the home page. Enabling this option puts the plugin in a test mode where all the errors and warnings will the caught and displayed to you before any subscriptions are created. Any rows with errors will not be imported however, rows with warnings will be. 
5.  Fix any errors and unnecessary warnings with your import file and repeat until the test run completes cleanly or continue the import from here, regardless of errors. 
6.  __Do not!__ exit the import process or refresh the page mid-way through importing. Above the final import results table has a progress percentage indicator along with the expected time for the entire import to complete. We recommend leaving the importer running until you receive the timeout error which happens after 6 minutes of no requests coming through.
7.  Disable any order and subscription related emails you do not want to send before beginning the import.

# Formatting your CSV file
The Subscriptions CSV Import suite makes it possible to bulk import subscriptions, but only if data is formatted correctly.

These are the general formatting rules which your CSV data must adhere to:
* The first row must include column headers - this is so the plugin knows where to map the fields.
* Each subscription needs to have its own row. Note that all columsn don't need to be filled in
* Two-letter country and state/county abbreviations should be used when possible.
* Date fields need to be in the format: y-m-d H:i:s (MySQL format - 2010-08-12 17:45:13).

## Importing Subscriptions
Importing subscriptions involves setting up a CSV file containing various column headers. The currently supported headers fall under one of the three groups: Customers, Subscription and Custom. If any of the following values are used in the CSV as column headers, they will automatically be selected upon entering the mapping process.

### Subscription billing, shipping and customer fields
* `customer_id`
* `customer_email`
* `customer_username`
* `customer_password`
* `billing_first_name`
* `billing_last_name`
* `billing_address_1`
* `billing_address_2`
* `billing_city`
* `billing_state`
* `billing_postcode`
* `billing_country`
* `billing_email`
* `billing_phone`
* `billing_company`
* `shipping_first_name`
* `shipping_last_name`
* `shipping_address_1`
* `shipping_address_2`
* `shipping_city`
* `shipping_state`
* `shipping_postcode`
* `shipping_country`

##### Accepted Column Fields
* Billing/shipping information – if this information is not provided in the CSV, we will attempt to get the information from the users account information
* `customer_id` - this needs to be an integer. Note: Can be left blank when creating a new user or if username and/or email of an existing user is present.

### Order Fields
* `line_total`
* `line_tax`
* `line_subtotal`
* `line_subtotal_tax`
* `order_discount`
* `cart_discount`
* `order_shipping_tax`
* `order_shipping`
* `order_tax`
* `order_total`
* `payment_method`
* `payment_method_title`
* `shipping_method`
* `shipping_method_title`
* `download_permission_granted`

##### Accepted Order Column Values
The following columns have some requirements for acceptable values or formats.
* `payment_method` – Leave this blank if you wish to import your subscriptions with manual renewals. If you wish to set the 
* `shipping_method` - This should be the shipping method id as seen in the table at WooCommerce > Settings > Shipping page, i.e. "free_shipping" or "flat_rate", defaults to an empty shipping method.
* `download_permission_granted` - value can be either yes or true; anything else will not grant download permissions for the subscription product in the order.
* All dollar amounts need to be either an integer or decimal value for instance, “5.65”, “3”, “127.2” are all valid entries.

### Subscription Fields
* `product_id` - this must contain the id of either a regular or variable subscription product within your store.  
* `status` - can be one of: active, expired, pending, on-hold or cancelled.
* `start_date` - Defaults to the current time if left empty.
* `trial_end_date` - If set, the trial end date must come after the start date.
* `next_payment_date` - If set, the next payment date must come after the start date and trial end date
* `end_date` - If not set, the subscription end date will be left empty.
* `billing_period` - defaults to the products billing period. Must be either: `day`, `week`, `month`, `year`. An invalid billing period will appear in test results.
* `billing_interval` - 
* 

##### Invalid subscription data
If any of the above columns contains invalid data, they will be shown in the test results. If you choose to ignore the errors and continue to import with invalid data, no subscription will be imported for that row in the CSV.


### Importing Custom Fields
* `custom_user_meta`, `custom_post_meta` -
Use these columns to add custom meta on subscription post or user. Multiple columns can be mapped to these custom fields and note that the value of the column header in the CSV will be used as the meta key. For example, if you want to add `'_terms' => true` as post meta. You will need to have a column in your CSV with header `_terms` and map it to `custom_post_meta`.
* `custom_user_post_meta` - Use this column if you wish to add custom data to both user and post meta using the same `meta_key` and value. Like previously stated, the value of the column header in the CSV will be used as the meta_key value.


### Supported Payment Gateways

The importer currently supports any payment gateway that supports the new WooCommerce Subscriptions `woocommerce_subscription_payment_meta` filter. Each payment method requires different pieces of information in order to renew properly, therefore here's a short list of the information needed and column headers for the more popular payment methods used on Subscriptions.
  - __PayPal Reference Transactions__: `_paypal_subscription_id`
  - __Stripe__: `_stripe_customer_id`
  - __Authorize.net CIM__: `wc_authorize_net_cim_customer_profile_id` and `wc_authorize_net_cim_payment_profile_id`

**Error handling**: say if you try to import a subscription using `stripe` but are missing the `_stripe_customer_id`, the subscription will not be created and the import will fail with a message detailing which payment meta is missing.

### List of Warnings

- Shipping method for the subscription has been set to empty.
- No recognisable payment method has been specified. Default payment method being used.
- The following shipping address fields have been left empty: [ LIST_OF_FIELDS ].
- The following billing address fields have been left empty: [ LIST_OF_FIELDS ].
- Used default subscription status as none was given.
- Download permissions cannot be granted because your current WooCommerce settings have disabled this feature.

A link to edit the order is given at the end of the list of warnings.

### List of Fatal Errors

- The product_id is not a subscription product in your store.
- An error occurred with the customer information provided.
  - Occurs when the user_id provided doesn't exist, the email doesn't exist, the username is invalid and there's not enough information to create a new user.
- Invalid payment method meta data

## Subscription Import Options

* Test Mode - Enabling this option places the import process in a 'Dry Run" mode where no orders are created, but if sufficient information is given, a new will be created. This is very useful for running test imports prior to the live import.
* Email passwords - Having this option ticked means that when the importer creates a new customer, the customer will receive a registration email containing their login details along with their temporary password.
* Add memberships

### New Customers and Passwords

When the importer doesn't recognise the given customer by the username or email (in the CSV), it will create a new user with those values. 
The minimum requirements for creating a new user is simply an email address. If no username is given when creating a new user, the importer will to create a username from the email. Say you you need to create a new user and have only given the email address, janedoe@example.com, the importer will try a new user with username janedoe. If this username is already taken we then try the username janedoe1, janedoe2 and so on; until it finds a free username (i.e janedoe102). You can specify a password to give the user in the CSV otherwise the customer's account is created with the password being securely generated by WordPress. This password can be emailed to the customer by selecting the 'Email Password' option at the first step of importing (not selected by default). If left unticked, all your new customers will need to go through the forgot-your-password process which will let them reset their details via email.

### Subscription Importer Simplifications
* product_id/variation_id – variable subscription ids must also be placed in the product_id column as well as regular subscriptions
* Billing/shipping Information - If the shipping/billing information is not provided in the CSV, the importer will try gather the information from the user's settings before leaving the values empty.
* payment_method/payment_method_title – the values used in these columns are also used for the recurring_payment_method and recurring_payment_method_title
* If the following values are not specified in the CSV, they will be set to $0 on the order (i.e. taxes will not be calculated):
  * `line_subtotal_tax`
  * `line_tax`
* If the following values are not specified in the CSV, they will be set to the subscription product price (regardless of whether a tax value is specified or not):
  * `line_subtotal`
  * `line_total`
  * `order_total`

## How to use the Importer

### Step 0: Make sure products exist
 - The WooCommerce Subscriptions Importer requires an existing subscription product before you can create subscriptions to that product. You can either [create a subscription](http://docs.woothemes.com/document/subscriptions/store-manager-guide/#section-1) manually if you only have a small number of different products, or use the [Product CSV Import Suite](http://www.woothemes.com/products/product-csv-import-suite/) if you need to create a large number of different subscription products.

### Step 1: Upload your CSV file
 - Locate the CSV file by searching for the file on disk.
 - Tick/Untick whether you want to run in test mode before importing to see warnings or fatal errors

### Step 2: Map the CSV fields
 - Each column header found in the CSV will be listed as a row in the table on this page, along with a dropdown menu. Use the dropdown menu to find and match the information to a value known by the importer. <strong>You must not have the same fields mapped more than once unless it's found under the custom group.</strong>
 - List of possible fields to map to are [above](https://github.com/Prospress/woocommerce-subscriptions-importer#importing-subscriptions)
 - Press Import or Test CSV depending on whether you are running the import in test mode first

### Step 3a: Test Mode
If you chose to run the import test mode beforehand, you should see something that looks similar to this.
![Test mode results](https://cldup.com/bFEXxTaBkL.png)
 This table shows all the errors and warnings that occurred while importing without actually creating the new customers and subscriptions. The beauty of running the importer firstly in test mode is that, from here, you can either exit the import process to fix up the CSV or continue importing the file. We strongly recommend you continue to run the importer in test mode until you are satisfied that all the errors are cleared.

### Step 3b: Import Completion Table
![Completion Table Screenshot](https://i.cloudup.com/VVsB5aBCHf-2000x2000.png)

##FAQ
#### 1. Is it possible to make sure the active subscriptions will still work?
It sure is! When importing active subscriptions, it's important that the subscription id's are provided in the CSV. Depending on the payment gateway being used, the information required varies (see below).
  - With __PayPal__, make sure you have set the paypal_subscriber_id field in the CSV ( PayPal sometimes call this the recurring payment profile ID).
  - When using __Stripe__, ensure the stripe_customer_id field is set in the CSV.
  - For those using __Authorize.net__, you will need to provide both the wc_authorize_net_cim_customer_profile_id and the wc_authorize_net_cim_payment_profile_id fields in the CSV. Both pieces of information are required!

#### 2. Can subscriptions still be imported with an unsupported payment gateway?
Yes but first, it should be understood that they are two possible solutions to consider:

  - defaulting to manual renewals;
  - allow the customer to change their payment option after the first payment fails.

Firstly, if you are using a payment gateway that is not supported by the CSV Importer, the subscriptions will still successfully import however they will be set to require manual renewal payments and will have the following warning on the import completion table, as shown in the image below.
![Unsupported Payment Method](https://i.cloudup.com/Ktkeu1FoVS-2000x2000.png)

If Subscriptions are set to manual renewals, it is very difficult (often impossible) to change them to an automatic renewal payment method, however it is relatively simple to change a payment method from automatic to manual renewals. Beforehand, you can [submit a request](http://support.woothemes.com/) for the payment method that you wish to use to be supported, or the other alternative is to import the subscription with another and use random text as the `stripe_customer_id` (a different payment method can be used as long as it is supported by the CSV Importer and supports the changing payment methods feature, see more info on this [here](http://docs.woothemes.com/document/subscriptions/payment-gateways/#section-1)). The stripe payment gateway with random text method is used to force the first payment to fail, allowing your customers to login and setup their preferred payment method by clicking the 'Change Payment Method' button on their My Account page and going through the checkout.

If the later approach is taken, we strongly recommend that you notify all affected customers about the change to avoid any confusion in the future.
 