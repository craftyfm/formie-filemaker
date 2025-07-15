# Formie Filemaker

Send form data from Formie to Filemaker

## Requirements

This plugin requires Craft CMS 5.0.0 or later, and PHP 8.0.2 or later.

## Installation

You can install this plugin from the Plugin Store or with Composer.

#### From the Plugin Store

Go to the Plugin Store in your project’s Control Panel and search for “formie-filemaker”. Then press “Install”.

#### With Composer

Open your terminal and run the following commands:

```bash
# go to the project directory
cd /path/to/my-project.test

# tell Composer to load the plugin
composer require craftyfm/craft-formie-filemaker

# tell Craft to install the plugin
./craft plugin/install formie-filemaker
```

This plugin adds Filemaker to the Webhook settings in Formie.

Send Formie submissions to Claris Filemaker via Data API v2.

This plugin sends the form data to a field name ```webhook_payload``` on your Filemaker layout (add this field to the table & layout) via the Webhook Integration settings in ```Formie->Settings->Webhook```

If you find any issues, please send a message via https://github.com/trade74/formie-filemaker/issues





