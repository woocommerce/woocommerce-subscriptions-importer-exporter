=== Subscriptions CSV Importer and Exporter for WooCommerce ===
Contributors: prospress
Tags: woocommerce, subscriptions, importer, exporter, csv
Requires at least: 4.0
Tested up to: 4.6.1
Stable tag: 1.0
License: GPLv2
License URI: http://www.gnu.org/licenses/gpl-2.0.html
 
Import and export subscriptions to your WooCommerce store.
 
== Description ==
 
= Before you begin =
 
Importing your subscriptions to WooCommerce is a complicated process.

To maximize your changes of success with an import, please read through this documentation in its entirety.

There is no substitution for this step. Fully understanding the details of the import process will save you time by avoiding mistakes that may become serious issues in the future.
 
= Importer =
 
With the WooCommerce Subscriptions CSV Importer, you can import subscriptions from a CSV file into your WooCommerce store. This is particularly useful when migrating stores from a different eCommerce platform.

The subscriptions will be setup with the [WooCommerce Subscriptions](https://www.woothemes.com/products/woocommerce-subscriptions/) extension to process future recurring payments.

![](https://cldup.com/r53E41w11p.png)
 
= Exporter =
 
With the Subscriptions CSV Exporter, you can download a comma delimited (CSV) file that contains all the details of the subscriptions in your WooCommerce store.

You can optionally filter the exported data to export only subscriptions with a specific:

* status, like active or on-hold
* recurring payment method, like PayPal or Stripe
customer set as the subscriber

You can also choose which data to include in the CSV file.

![](https://cldup.com/WK-9aRHQ7r.png)

Please note: this extension will not export any related orders for your subscriptions (this includes parent and renewal orders, etc); to export orders, you will need the WooCommerce Order CSV Exporter.
 
== Installation ==
 
1. First upload the plugin's files to the /wp-content/plugins/ directory
1. Activate the plugin through the Plugins menu in WordPress
 
= Importer Guide =
 
1. Create a CSV file with all your subscription data formatted for import (this is by far the most complicated step). The [CSV Formatting Guide](https://github.com/Prospress/woocommerce-subscriptions-importer-exporter#csv-formatting-guide) details the options and requirements for your CSV's content.
1. Go to **WooCommerce > Subscription Importer**
1. Upload your CSV file
1. Select Import Options:
	1. Check **Run in test mode** to validate your CSV file without creating corrupted subscriptions
	1. Check **Email Passwords** to email customers that are newly created their account details
	1. Check **Add memberships** to grant subscribers any membership/s plan corresponding to subscription products
1. Click **Upload and file and import**
1. Review each column of data in your file to make sure the Importer has mapped it to the correct column header. 
1. Click **Test CSV**
1. If there are errors, fix up the CSV file and return to step 1.
1. If there are no errors, click **Run Import**
1. Review the [Import Completion Table](https://github.com/Prospress/woocommerce-subscriptions-importer-exporter#import-completion-table) and [Import Logs](https://github.com/Prospress/woocommerce-subscriptions-importer-exporter#import-error-logs) for uncover any issues with the import
 
= Exporter Guide =
 
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
 
== Frequently Asked Questions ==
 
= Is it possible to make sure the active subscriptions will still process automatic payments? =
 
Yes. If your subscriptions payment gateway supports [automatic recurring payments](http://docs.woothemes.com/document/subscriptions/payment-gateways/), it may also be able to have its payment method meta data imported to link the subscription to the payment gateway to process future automatic recurring payments.

When importing active subscriptions, it's important that the correct payment method meta data is provided in the CSV. Depending on the payment gateway being used, the information required varies. For details, see the section on [Importing Payment Gateway Meta Data](https://github.com/Prospress/woocommerce-subscriptions-importer-exporter#importing-payment-gateway-meta-data).
 
= Can subscriptions using PayPal Standard be imported? =
 
No. Due to [PayPal Standard limitations](https://docs.woothemes.com/document/subscriptions/payment-gateways/#paypal-limitations), the importer can not migrate subscriptions using PayPal Standard as the payment method.

The same limitations do not apply to [PayPal Reference Transactions](https://docs.woothemes.com/document/subscriptions/faq/paypal-reference-transactions/). Therefore the CSV importer can migrate subscriptions which use a PayPal Billing Agreement to process recurring payments via Reference Transactions.

If you have subscriptions with PayPal Standard and you're interested in getting your customers to use a different payment method, you can import the subscriptions with a dummy payment method, as mentioned below, and request that your customers [change the payment method](https://docs.woothemes.com/document/subscriptions/customers-view/#section-5) on those subscriptions.
 
= Are there any more FAQs? =
 
Yes, you can view our full list of FAQ's on our [GitHub repository](https://github.com/Prospress/woocommerce-subscriptions-importer-exporter#importer-faq)
 
== Screenshots ==
 
1. Importer Main Page
2. Import Test Run Results
3. Import Completion Screen
4. Exporter Main Page
5. Export CSV Headers
 
== Changelog ==
 
= 1.0 =
 
* Initial release.
 
== Support ==
 
The WooCommerce Subscriptions Importer and Exporter is released freely and openly to help WooCommerce developers migrate subscriber data to [WooCommerce Subscriptions](https://woocommerce.com/products/woocommerce-subscriptions/). Even with this plugin, migrations involve a lot of manual work to format subscription data correctly and test imports. Neither [Prospress](https://prospress.com/) nor [WooCommerce](http://woocommerce.com/) provide services to complete a migration with this tool.

For help with a migration, please contact [WisdmLabs](https://wisdmlabs.com/), an official partner for this extension.

You can learn more about the migration service WisdmLabs offer and contact them via their [WooCommerce Subscriptions Migration page](https://wisdmlabs.com/woocommerce-subscriptions-migration-partner/).

Prospress do not provide support for migration issues. This means Prospress can not help with CSV formatting, fixing broken renewals or other issues with subscriptions imported incorrectly. Similarly, issues with subscriptions created with this plugin are not supported via the WooCommerce support system.

If you think you have found a bug in the extension, problem with the documentation or limitation in the data that can be imported, please [open a new issue to report it](https://github.com/Prospress/woocommerce-subscriptions-importer-exporter/issues/new).
 
== Contribute ==

Want to get involved with the WooCommerce Stellar extension's development, join us on [GitHub](https://github.com/Prospress/woocommerce-subscriptions-importer-exporter).
