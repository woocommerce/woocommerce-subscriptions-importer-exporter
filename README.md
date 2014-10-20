# WooCommerce Subscriptions Importer

##Overview
The WooCommerce Subscriptions CSV Import Suite allows you to easily import your subscriptions from a CSV file into your WooCommerce Store. This is particularly useful when migrating stores and importing from a different eCommerce platform - which may or may not use exactly the same fields as WooCommerce. Subscriptions are ordered and mapped to existing or new users. The order item is mapped to existing subscription or variable subscription id’s. A total of 58 fields can set in the CSV giving you the utmost control over the import process.

## Before Installing
Please note that it is not possible to move subscriptions created with PayPal to a store using a different URL to the store where the customer initially signed up for the subscription. This is not a limitation in WooCommerce Subscriptions, it is a limitation with PayPal and it’s IPN system. If you're interested in getting your customers to use a different payment method (we recommend stripe), then try using the Failed Payment approach explained here: http://docs.woothemes.com/document/subscriptions/customers-view/#section-3

## Installation
To install WooCommerce Subscription CSV Import Suite: 

1. Download the extension from [here](https://github.com/Prospress/woocommerce-subscriptions-importer/archive/master.zip)
2. Go to plugins > Add New > Upload and select the ZIP file 
3. Click Install Now, and then Activate
4. Go to **WooCommerce > Subscription Importer** to start importing your data

## Must Read
The following list of points can be taken to maximize success and minimize time and problems that occur while using this plugin:

1.	Read through this entire documentation so you know how the import process operates and its requirements. There is no substitution for this step; yes reading documentation is a pain and takes time, but it will actually save you time in the end.
2.	Make sure the product_id in the CSV exists as a regular subscription or variable subscription.
3.	Set the column names and data formats for the files as described below in this documentation. This is not a requirement however if the columns in your CSV are named the same as described below, they will automatically be selected - saving you time.
4.	Before importing, run the importer in test mode first to avoid unexpected outcomes, test mode can be enabled by checking the box displayed on the home page. Enabling this option puts the plugin in a test mode where all the errors and warnings will the caught and displayed to you before any orders with the subscriptions are created. Any rows with errors will not be imported however, rows with warnings will be. 
5.	Fix any errors and unnecessary warnings with your import file and repeat until the test-mode run completes cleanly or continue the import from here, regardless of errors. 
6.  Don't exit the import process or refresh the page mid-way through importing. Above the final import results table is a progress percentage indicator along with the expected time for the entire import to complete. We recommend leaving the importer running until you receive the timeout error which happens after 6 minutes of no requests coming through.
7.	Disable any order and subscription related emails you do not want to send under "WooCommerce > Settings > Emails". When a subscription is imported, an order is created to record the subscription's details and that order is marked as completed. If you have the "Order Complete" email enabled, this email will be sent to the customer.

# Formatting your CSV file
The Subscriptions CSV Import suite makes it possible to bulk import subscriptions, but only if data is formatted correctly.

These are the general formatting rules which your CSV data must adhere to:
* The first row must include column headers - this is so the plugin knows where to map the fields.
* All values should be surrounded with double-quotes "" to ensure proper parsing.
* Each subscription should have its own row. Not all columns need to be completed for all rows.
* Locality fields: two-letter country and state/county abbreviations should be used when possible.
* Date fields should be in the format YYYY-MM-DD HH:MM:SS (MySQL format). You can use the simplified form of YYYY-MM-DD to specify just a day or YYYY-MM-DD HH:MM:SS to specify a day and time. If specifying a time, use 24hour time and GMT/UTC as the timezone. For example, a valid value to set a date to the 12th of April 2014: '2014-04-12'. To set the date to 1:00pm on that day, the value should be: 2014-04-12 13:00:00.

## Importing Subscriptions
Importing subscriptions involves setting up a CSV file containing various column headers. The currently supported headers fall under one of the three groups: Customers, Subscription, Orders. If any of the following values are used in the CSV as column headers, they will automatically be selected upon entering the mapping process.

### Customer Fields
*	customer_id
* customer_email
*	customer_username
*	customer_password
*	billing_first_name
*	billing_last_name
*	billing_address_1
*	billing_address_2
*	billing_city
*	billing_state
*	billing_postcode
*	billing_country
*	billing_email
*	billing_phone
*	billing_company
*	shipping_first_name
*	shipping_last_name
*	shipping_address_1
*	shipping_address_2
*	shipping_city
*	shipping_state
*	shipping_postcode
*	shipping_country

##### Accepted Customer Column Fields
The following columns have some requirements for acceptable values or formats. 
* Billing/Shipping information – If this information is not provided in the CSV, the importer will attempt to get the information from the users account information
* customer_id - this needs to be the integer value which represents the WP User. Can be left blank when creating a new user or if username and/or email of an existing user is present.

### Order Fields
*	recurring_line_total
*	recurring_line_tax
*	recurring_line_subtoal
*	recurring_line_subtotal_tax
*	line_total
*	line_tax
*	line_subtotal
*	line_subtotal_tax
*	order_discount
*	cart_discount
*	order_shipping_tax
*	order_shipping
*	order_tax
*	order_total
*	payment_method
*	payment_method_title
*	stripe_customer_id
*	paypal_subscriber_id
*	shipping_method
*	shipping_method_title
*	download_permission_granted

##### Accepted Order Column Values
The following columns have some requirements for acceptable values or formats.
*	payment_method – the currently supported payment methods are PayPal, Stripe and Authorize.net. If anything other than paypal, stripe or authorize_net_cim is used, the import will default to manual renewal.
*	shipping_method - This should be the shipping method id as seen in the table at WooCommerce > Settings > Shipping page, i.e. "free_shipping" or "flat_rate", defaults to an empty shipping method.
*	download_permission_granted - value can be either yes or true; anything else will not grant download permissions for the subscription product in the order.
*	All dollar amounts need to be either integer or decimal value for instance, “5.65”, “3”, “127.2” are all valid entries.

### Subscription Fields
*	product_id - this must contain either the id of a regular or variable subscription product within your store.  
* subscription_status - can be one of: active, expired, pending, on-hold or cancelled.
*	subscription_start_date - if provided this must be in the format YYYY-MM-DD (for example: "2014-07-21"). If not set, the current date will be used.
*	subscription_expiry_date - if provided this must be in the format YYYY-MM-DD (for example: "2014-07-21"). If not set, the subscription trial expiration date will be calculated based on the free trial set on the product (if any) and the subscription's start date.
*	subscription_end_date - if provided this must be in the format YYYY-MM-DD (for example: "2014-07-21"). If not set, the subscription end date will be left empty - this date is simply a record of a day in the past the subscription ended, either due to expiraiton or cancellation.


### Importing Custom Fields
* custom_user_meta
* custom_order_meta
Use these columns to add custom information on order meta or user meta. The value of the column header in the CSV used as the meta key. For example, you want to add `_terms => true` as order meta for a row in the CSV. Map the column in the CSV with the header `_terms` to `custom_order_meta`.

* custom_user_order_meta
Use this column if you wish to add custom data to both user and order meta using the same `meta_key` value. The value of the column header in the CSV will be used as the meta_key value.


### Supported Payment Gateways

The importer currently supports three payment gateways, each requiring different pieces of information in order to successfully transfer active subscriptions.
  - __Paypal__: paypal_subscriber_id 
  - __Stripe__: stripe_customer_id
  - __Authorize.net__: wc_authorize_net_cim_customer_profile_id and wc_authorize_net_cim_payment_profile_id

The importer will detect missing information and provide a pop up before you import. For instance, if you have 'paypal' listed as the payment_method and have not specifed a paypal_subscriber_id field or that field is empty, you will receive a pop up message detailing what information is missing with an option to cancel or continue. If you wish to continue, those subscriptions will default to using manual recurring payments.

If your store uses two payment gateways (ie. PayPal and Stripe) both the paypal_subscriber_id and stripe_customer_id fields will need to mapped and for those rows where the payment_method is set to 'paypal' the paypal_subscriber_id needs to be set while stripe_customer_id can be left empty and vica versa.

### List of Warnings

- Shipping method and/or title for the order has been set to empty.
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

## Subscription Import Options

* Delimiter - this allows you to specific any other character as the delimiter of the imported CSV; defaulted to the comma character.
* Test Mode - Enabling this option places the import process in a 'Dry Run" mode where no orders are created, but if sufficient information is given, a new will be created. This is very useful for running test imports prior to the live import.
* Send off registration email - Having this option ticked means that when the importer creates a new customer, the customer will receive a registration email containing their login details along with their temporary password.
* AJAX Request Limit - the amount of CSV rows handled at once per AJAX call can be modified by defining the WCS_REQ_LIMIT constant in wp_config.php; defaults to 15.

### New Customers and Passwords

When the importer doesn't recognise the given customer by the username or email (in the CSV), it will create a new user with those values. 
The minimum requirements for creating a new user is simply an email address. If no username is given when creating a new user, the importer will to create a username from the email. Say you you need to create a new user and have only given the email address, janedoe@example.com, the importer will try a new user with username janedoe. If this username is already taken we then try the username janedoe1, janedoe2 and so on; until it finds a free username (i.e janedoe102). You can specify a password to give the user in the CSV otherwise the customer's account is created with the password being securely generated by WordPress. This password can be emailed to the customer by selecting the 'Email Password' option at the first step of importing (not selected by default). If left unticked, all your new customers will need to go through the forgot-your-password process which will let them reset their details via email.


### Subscription Importer Simplifications
* product_id/variation_id – variable subscription ids can also be placed in the product_id column as well as regular subscriptions
* Billing/shipping Information - If the shipping/billing information is not provided in the CSV, the importer will try gather the information from the user's settings before leaving the values empty.
* Recurring Order meta Data - The values for recurring_shipping_method/title, order_recurring_shipping_total, order_recurring_shipping_tax_total, recurring
* Billing First name - to avoid the billing name being left null, the customers_username has been used if no billing_first_name is set by the user or the CSV.
* payment_method/payment_method_title – the values used in these columns are also used for the recurring_payment_method and recurring_payment_method_title
* If the following values are not specified in the CSV they will be set to $0 on the order (i.e. taxes will not be calculated):
  * recurring_line_subtotal_tax
  * recurring_line_tax
  * line_subtotal_tax
  * line_tax
* If the following values are not specified in the CSV they will be set to the subscription product's recurring price (regardless of whether a tax value is specified or not):
  * recurring_line_subtotal
  * recurring_line_total
* If the following values are not specified in the CSV, they will be set to the subscription product's sign-up fee (if any) plus recurring price, if there is no free trial (regardless of whether a tax value is specified or not):
  * line_subtotal
  * line_total
  * order_total
  * order_recurring_total

## How to use the Importer

### Step 0: Make Sure Products Exist
  - The WooCommerce Subscriptions Importer requires an existing subscription product to exist before you can create subscriptions to that product. You can either [create a subscription](http://docs.woothemes.com/document/subscriptions/store-manager-guide/#section-1) manually if you only have a small number of different products, or use the [Product CSV Import Suite](http://www.woothemes.com/products/product-csv-import-suite/) if you need to create a large number of different subscription products.

### Step 1: Upload your CSV File.
  - Locate the CSV file by searching for the file on disk.
  - Specify the delimiting character that separates each column; defaults to comma character `,`
  - Tick/Untick whether you want to run in test mode before importing to see warnings or fatal errors, and also if want to email new customers their login credentials

### Step 2: Map the CSV Fields
- Each column header found in the CSV will be listed as a row in the table on this page, along with a dropdown menu. Use the dropdown menu to find and match the information to a value known by the importer. <strong>Must not have the same fields mapped more than once unless it's either `custom_user_meta`, `custom_order_meta` or `custom_user_order_meta`.</strong>
- List of possible fields to map to are [above](https://github.com/Prospress/woocommerce-subscriptions-importer#importing-subscriptions)
- Press Import or Test CSV depending on whether you are running the import in test mode first

### Step 3a: Test Mode
If you chose to run the import test mode beforehand, you should see something that looks similar to this.
![Test mode results](http://oi57.tinypic.com/hv88k7.jpg)
 This table shows all the errors and warnings that occurred while importing without actually creating the new customers and subscription orders. The beauty of running the importer firstly in test mode is that, from here, you can either exit the import process to fix up the CSV or continue importing the file.

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
