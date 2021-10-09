# E-nkap - Mobile Money Gateway for Easy Digital Downloads
E-nkap Gateway for Easy Digital Downloads is a simple and powerful Payment plugin for WordPress

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
We assume you already installed Easy Digital Downloads and configured it successfully

1. Upload `e-nkap` to the `/wp-content/plugins/` directory

   Install Using GIT
```sh
cd wp-content/plugins

git clone https://github.com/camoo/enkap-edd-gateway.git e-nkap-edd-gateway

# install dependencies
cd e-nkap-edd-gateway
composer install
```

## Auto installation and Manage the plugin
1. In your WordPress Dashboard go to \"Plugins\" -> \"Add Plugin\".
2. Search for \"Enkap Payment\".
3. Install the plugin by pressing the \"Install\" button.
4. Activate the plugin by pressing the \"Activate\" button.
5. Open the settings page for Easy Digital Downloads and click the \"Checkout\" tab.
6. Click on the sub tab for \"Enkap Payment\".
7. Configure your Enkap Gateway settings.