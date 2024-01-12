# Recurly Test Client

This module provides a mock API client that can be substituted for the `\Drupal\recurly\RecurlyClientFactory` (recurly.client) service to bypass making live API requests and return data from fixtures instead. It is intended to be used when running tests for the Recurly module.

## Usage

In most cases you'll need to:

1. Enable the module while running tests
2. Clear any existing responses in the setUp method
3. Tell the Mock client which fixture to use when API requests are made

See \Drupal\Tests\recurly\Kernel\RecurlyInvoicesControllerTest for an example.

## Fixtures

The fixtures in fixtures/ are mostly copied from the Recurly PHP library, though some modifications have been made. Primarily making the account_id's match up between different fixtures we can do more complete functional testing.
