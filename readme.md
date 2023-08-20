This contains the source files for the "*Omnipedia - Warmer*" Drupal module, which
provides cache warming-related functionality for [Omnipedia](https://omnipedia.app/).

⚠️ ***[Why open source? / Spoiler warning](https://omnipedia.app/open-source)***

----

# Requirements

* [Drupal 9.5 or 10](https://www.drupal.org/download) ([Drupal 8 is end-of-life](https://www.drupal.org/psa-2021-11-30))

* PHP 8.1

* [Composer](https://getcomposer.org/)

## Drupal dependencies

Before attempting to install this, you must add the Composer repositories as
described in the installation instructions for these dependencies:

* The [`omnipedia_core` module](https://github.com/neurocracy/drupal-omnipedia-core)

----

# Installation

## Composer

### Set up

Ensure that you have your Drupal installation set up with the correct Composer
installer types such as those provided by [the `drupal/recommended-project`
template](https://www.drupal.org/docs/develop/using-composer/starting-a-site-using-drupal-composer-project-templates#s-drupalrecommended-project).
If you're starting from scratch, simply requiring that template and following
[the Drupal.org Composer
documentation](https://www.drupal.org/docs/develop/using-composer/starting-a-site-using-drupal-composer-project-templates)
should get you up and running.

### Repository

In your root `composer.json`, add the following to the `"repositories"` section:

```json
"drupal/omnipedia_warmer": {
  "type": "vcs",
  "url": "https://github.com/neurocracy/drupal-omnipedia-warmer.git"
}
```

### Installing

Once you've completed all of the above, run `composer require
"drupal/omnipedia_warmer:2.x-dev@dev"` in the root of your project to have
Composer install this and its required dependencies for you.

----

# Major breaking changes

The following major version bumps indicate breaking changes:

* 2.x:

  * Requires Drupal 9.5 or [Drupal 10](https://www.drupal.org/project/drupal/releases/10.0.0).

  * Requires PHP 8.1.
