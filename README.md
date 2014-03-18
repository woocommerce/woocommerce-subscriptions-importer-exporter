## WooCommerce Subscriptions Importer

### Step 0: Make sure products exist.
  - The WooCommerce Subscriptions Importer requires an existing subscription product to exist before you can create subscriptions to that product. You can either [create a subscription](http://docs.woothemes.com/document/subscriptions/store-manager-guide/#section-1) manually if you only have a small number of different products, or use the [Product CSV Import Suite](http://www.woothemes.com/products/product-csv-import-suite/) if you need to create a large number of different subscription products.

### Step 1: Upload CSV file.
  - Locate the CSV file by searching for the file on disk or enter in the file path.
  - Specify the delimiting character that separates each column; defaults to comma character `,`

### Step 2: Map the fields to the file 
- Each column header found in the file will listed as a row in the table on this page, along with a dropdown menu. Use the dropdown menu to find and match the information to a value known by the importer. <strong>Must not have the same fields mapped more than once!</strong>
- List of possible fields to map to are <a href="https://github.com/thenbrent/woocommerce-subscriptions-importer/edit/master/README.md#list-of-possible-fields">below</a>

### Step 3: Completion Table
<a href="http://tinypic.com?ref=suyil5" target="_blank"><img src="http://i59.tinypic.com/suyil5.png" border="0" alt="Image and video hosting by TinyPic"></a>
#### List of Warnings
- Shipping method and/or title for the order has been set to empty.
- No recognisable payment method has been specified therefore default payment method being used.
- The following shipping address fields have been left empty: [ LIST_OF_FIELDS ].
- The following billing address fields have been left empty: [ LIST_OF_FIELDS ].
- Used default subscription status as none was given.

A link to edit the order is given at the end of the list of warnings.

#### List of Fatal Errors
- The product_id is not a subscription product in your store.
  - Occurs when no product_id is given or the product_id is not a subscription product within your store.
- An error occurred with the customer information provided.
  - Occurs when the user_id provided doesn't exist, the email doesn't exist, the username is invalid and there's not enough information to create a new user.


### Things to consider before importing
- Specify the user_id for existing users and only fill in the username and email address when needing to create a new user. If the specified email already exists, the importer will make the order for that user, simarily for the username (email takes preference).
- If the following values are not specified in the CSV they will be set to $0 on the order: 
  - recurring_line_total, 
  - recurring_line_tax, 
  - recurring_line_subtotal, 
  - recurring_line_subtotal_tax, 
  - line_subtotal, 
  - line_total, 
  - line_tax, 
  - line_subtotal_tax.
- If also not specified in the CSV the `order_total` and `order_recurring_total` will be set to the subscription price tied to the product object.
- When creating a new user, if the billing_first_name column is empty, the username will be used to avoid the name Guest appearing in the completion table.
- more..

#### List of possible fields
NOTE: If any of these values are used in the CSV as column headers, they will automatically be selected in the dropdown menu.
- product_id (used for variation_ids values aswell)
- customer_id
- customer_email
- customer_username
- billing_first_name
- billing_last_name
- billing_address_1
- billing_address_2
- billing_ci
- billing_sta
- billing_postcode
- billing_country
- billing_email
- billing_phone
- shipping_first_name
- shipping_last_name
- shipping_company
- shipping_address_1
- shipping_address_2
- shipping_city
- shipping_state
- shipping_postcode
- shipping_country
- subscription_status
- subscription_start_date
- subscription_expiry_date
- subscription_end_date
- payment_method
- shipping_method
- shipping_method_title
- recurring_line_total
- recurring_line_tax
- recurring_line_subtotal
- recurring_line_subtotal_tax
- line_total
- line_tax
- line_subtotal
- line_subtotal_tax
- order_discount
- cart_discount
- order_shipping_tax
- order_shipping
- order_tax
- order_total
- order_recurring_total
- stripe_customer_id
- paypal_subscriber_id
- payment_method_title
