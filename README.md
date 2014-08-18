# WooCommerce Subscriptions Importer

##Overview
The WooCommerce Subscriptions CSV Import Suite allows you to easily import your subscriptions from a CSV file into your shop. This is particularly useful when migrating shops and importing from a different eCommerce platform, which may or may not use exactly the same fields as WooCommerce. Subscriptions are ordered and mapped to the existing or new users. The order item is mapped to existing subscription or variable subscription id’s. A total of 46 fields can set in the CSV giving you the most control over the import process, details below.

## Before Installing
Please note that it is not possible to move subscriptions created with PayPal to a store using a different URL to the store where the customer initially signed up for the subscription. This is not a limitation in WooCommerce Subscriptions, it is a limitation with PayPal and it’s IPN system. If you're interested in getting your customers to use a different payment method (we recommend stripe), then try using the Failed Payment approach explained here: http://docs.woothemes.com/document/subscriptions/customers-view/#section-3

## Installation
To install WooCommerce Subscription CSV Import Suite: 

1. Download the extension from <link>
2. Go to plugins > Add New > Upload and select the ZIP file 
3. Click Install Now, and then Activate
4. Go to **WooCommerce > Subscription Importer** to start importing your data

## Best Practices (Please Read!)
The following step can be taken to maximum success and minimize time and problems while using this plugin:

1.	Read through this entire documentation so you know how the import process operates and it’s requirements. There is no substitution for this step; yes reading documentation is a pain and take time, but it will actually save you time in the end.
2.	Make sure the product_id in the CSV exists as a regular subscription or variable subscription.
3.	Set the column names and data formats for the files as described below in this documentation.
4.	Before importing, run the importer in test mode first to avoid unexpected outcomes, test mode can be enabled by checking the box displayed after the CSV columns headers have been mapped. Enabling this option puts the plugin in a test mode where all the errors and warnings will the caught and displayed to you before any orders with the subscriptions are created. Any rows with errors will not be imported however, rows with warnings will be. 
5.	Fix any errors and unnecessary warnings with your import file and repeat until the test-mode run completes cleanly or continue the import from here, regardless of errors. 
6.	Disable any order and subscription related emails you do not want to send under "WooCommerce > Settings > Emails". When a subscription is imported, an order is created to record the subscription's details and that order is marked as completed. If you have the "Order Complete" email enabled, this email will be sent to the customer.

## Formatting your CSV file
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

### Accepted Customer Column Fields
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

### Accepted Order Column Values
The following columns have some requirements for acceptable values or formats.
*	payment_method – the currently supported payment methods are PayPal, Stripe and Authorize.net. If anything other than paypal, stripe or authorize.net is used, the import will default to manual renewal.
*	shipping_method - This should be the shipping method name as seen in the Order admin, i.e. "free_shipping", but can be any string that identifies the shipping method to you; defaults to an empty shipping method.
*	download_permission_granted - value can be either yes or true; anything else will not grant download permissions for the subscription product in the order.
*	All dollar amounts need to be either integer or decimal value for instance, “5.65”, “3”, “127.2” are all valid entries.

### Supported Payment Gateways
The importer currently supports three payment gateways, each requiring different pieces of information in order to successfully transfer active subscriptions.
  - __Paypal__: paypal_subscriber_id 
  - __Stripe__: stripe_customer_id
  - __Authorize.net__: authorize_net_cim_profile_id and authorize_net_cim_payment_profile_id

The importer will detect missing information and provide a pop up before you import. For instance, if you have 'paypal' listed as the payment_method and have not specifed a paypal_subscriber_id field or that field is empty, you will receive a pop up message detailing what information is missing with an option to cancel or continue. If you wish to continue, those subscriptions will default to using manual recurring payments.

If your store uses two payment gateways (ie. PayPal and Stripe) both the paypal_subscriber_id and stripe_customer_id fields will need to mapped and for those rows where the payment_method is set to 'paypal' the paypal_subscriber_id needs to be set while stripe_customer_id can be left empty and vica versa.

### Subscription Fields
*	product_id
*	subscription_status
*	subscription_start_date
*	subscription_expiry_date
*	subscription_end_date

### Accepted Subscription Column Values
*	product_id - this must contain either the id of a regular or variable subscription product within your store.  
*	subscription_start_date - If provided this must be in the format YYYY-MM-DD (for example: "2014-07-21"). If not set, the current date will be used.
*	subscription_trial_expiry_date - If provided this must be in the format YYYY-MM-DD (for example: "2014-07-21"). If not set, the subscription trial expiration date will be calculated based on the free trial set on the product (if any) and the subscription's start date.
*	subscription_expiry_date - If provided this must be in the format YYYY-MM-DD (for example: "2014-07-21"). If not set, the subscription expiration date will be calculated based on the length set on the product (if any) and the subscription's start date.
*	subscription_end_date - If provided this must be in the format YYYY-MM-DD (for example: "2014-07-21"). If not set, the subscription end date will be left empty - this date is simply a record of a day in the past the subscription ended, either due to expiraiton or cancellation.
*	subscription_status - Can be one of: active, expired, pending, on-hold or cancelled.

### Subscription Import Options
* Delimiter - this allows you to specific any other character as the delimiter of the imported CSV; defaulted to the comma character.
* Test Mode - Enabling this option places the import process in a 'Dry Run" mode where no orders are created, but if sufficient information is given, a new will be created. This is very useful for running test imports prior to the live import.
* Send off registration email - Having this option ticked means that when the importer creates a new customer, the customer will receive a registration email containing their login details along with their temporary password.
* AJAX Request Limit - the amount of CSV rows handled at once per AJAX call can be modified by defining the WCS_REQ_LIMIT constant in wp_config.php; defaults to 15.

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
  - Occurs when no product_id is given or the product_id is not a subscription product within your store.
- An error occurred with the customer information provided.
  - Occurs when the user_id provided doesn't exist, the email doesn't exist, the username is invalid and there's not enough information to create a new user.

### New Customers and Passwords
When the importer doesn't recognises the given customer by the username or email (in the CSV), it will create a new one with those values. This customer's account is created with the password being securely generated by WordPress. This password can be emailed to the customer by selecting the 'Email Password' option at the first step of importing which is already selected by default. If you choose to untick this option, all your new customers will need to go through the forgot-your-password process which will let them reset it via email.


### Subscription Importer Simplifications
* product_id/variation_id – variable subscription ids can also be placed in the product_id column as well as regular subscriptions
* Billing/shipping Information - If the shipping/billing information is not provided in the CSV the importer will try gather the information from the users settings before leaving the values empty.
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
* If the following values are not specified in the CSV they will be set to the subscription product's sign-up fee (if any) plus recurring price, if there is no free trial (regardless of whether a tax value is specified or not):
  * line_subtotal
  * line_total
  * order_total
  * order_recurring_total

## How to use the Importer

### Step 0: Make Sure Products Exist
  - The WooCommerce Subscriptions Importer requires an existing subscription product to exist before you can create subscriptions to that product. You can either [create a subscription](http://docs.woothemes.com/document/subscriptions/store-manager-guide/#section-1) manually if you only have a small number of different products, or use the [Product CSV Import Suite](http://www.woothemes.com/products/product-csv-import-suite/) if you need to create a large number of different subscription products.

### Step 1: Upload your CSV File.
  - Locate the CSV file by searching for the file on disk or enter in the file path.
  - Specify the delimiting character that separates each column; defaults to comma character `,`

### Step 2: Map the File's Fields
- Each column header found in the file will listed as a row in the table on this page, along with a dropdown menu. Use the dropdown menu to find and match the information to a value known by the importer. <strong>Must not have the same fields mapped more than once!</strong>
- List of possible fields to map to are <a href="https://github.com/thenbrent/woocommerce-subscriptions-importer/edit/master/README.md#import-subscriptions">above</a>

### Step 3a: Run in Test Mode
After ticking the box to run the importer in test mode, you should see something that look similar to the image below. From here you're given the option to either exit the import process to fix up the CSV or continue importing the file
![Test mode results](http://oi57.tinypic.com/hv88k7.jpg)

### Step 3b: Import Completion Table
![Completion Table Screenshot](http://i59.tinypic.com/suyil5.png)

##FAQ
#### 1. Is it possible to make sure the active subscriptions will still work?
Yes. When importing active subscriptions, it's important you that the subcription id's are provided in the CSV. Depending on the payment gateway being used, the information required varies (see below).
  - With <strong>PayPal</strong>, make sure you have set the paypal_subscriber_id field in the CSV ( PayPal sometimes call this the recurring payment profile ID).
  - When using <strong>Stripe</strong>, ensure the stripe_customer_id field is set in the CSV.
  - For those using <strong>Authorize.net</strong>, you will need to provide both the authorize_net_cim_profile_id and the authorize_net_cim_payment_profile_id fields in the CSV. Both pieces of information are required as the authorize_net_cim_profile_id value refers to the customer and the authorize_net_cim_payment_profile_id is used by Authorize.net to identify their payment information.
