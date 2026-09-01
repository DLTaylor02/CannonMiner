# Third-party notices

CannonMiner's source code is licensed under the MIT License in `LICENSE`.
That license applies to CannonMiner code only. It does not grant rights to
Google Maps content or change the licenses of Composer dependencies.

## Google Maps Platform

CannonMiner can call the Google Directions API and display route images with
the Maps Static API.
Those services and their returned content are governed by the operator's
Google Maps Platform agreement, service-specific terms, documentation, billing
terms, attribution rules, and applicable privacy requirements:

- https://cloud.google.com/maps-platform/terms
- https://cloud.google.com/maps-platform/terms/maps-service-terms
- https://developers.google.com/maps/faq

The standard Google Maps Platform terms generally restrict pre-fetching,
indexing, storing, resharing, and rehosting Google Maps content except where a
service-specific exception applies. CannonMiner's historical analysis requires
persistent storage of response fields. Collection is therefore disabled until
an administrator confirms that their agreement or separate permission permits
that use. This confirmation is an operational safeguard, not legal advice or a
guarantee of compliance.

Google Maps, Directions API, and Maps Static API are not included in, sponsored
by, or licensed under CannonMiner's MIT License. Attribution rendered by Google
in embedded maps must not be removed or obscured.

## Composer packages

Composer installs independently licensed packages, including Slim, Slim PSR-7,
Slim Twig View, Twig, Guzzle, and their transitive dependencies. Their license
files remain in `vendor/`. The installer also writes the resolved dependency
license inventory to `var/composer-licenses.json`; use that generated inventory
as the authoritative list for the installed versions.
