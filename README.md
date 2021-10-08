# Enkap - Mobile Money Gateway for WooCommerce
Enkap Gateway for WooCommerce is a simple and powerful Payment plugin for WordPress

You can add to WordPress, the ability to receive easily Mobile Money payment from Cameroon


The usage of this plugin is completely free. You have to just have an Enkap account:
* [Sign up](https://enkap.cm/) for a free account
* Ask Enkap Team for consumerKey and consumerSecret


# Features

* Pay with Cameroon MTN Mobile Money
* Pay with Cameroon Orange Mobile Money
* Pay with Express Union Mobile Money
* Pay with SmobilPay Cash

# Installation
We assume you already installed WooCommerce and configured it successfully

1. Upload `e-nkap` to the `/wp-content/plugins/` directory

   Install Using GIT
```sh
cd wp-content/plugins

git clone https://github.com/camoo/enkap-woocommerce-gateway.git e-nkap

# install dependencies
cd e-nkap
composer install
```

## Auto installation and Manage the plugin
1. In your WordPress Dashboard go to \"Plugins\" -> \"Add Plugin\".
2. Search for \"Enkap Payment\".
3. Install the plugin by pressing the \"Install\" button.
4. Activate the plugin by pressing the \"Activate\" button.
5. Open the settings page for WooCommerce and click the \"Checkout\" tab.
6. Click on the sub tab for \"Enkap Payment\".
7. Configure your Enkap Gateway settings.
