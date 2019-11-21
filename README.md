# silverstripe-data-object-to-fixture

Generate a YAML fixture from DataObjects

## Warnings

This is in very, very early development stages (this is basically an ugly first draft). Please be aware that:

- Namespaces might change
- Classes might change

Use at your own risk.

## Usage

```php
// Instantiate the Service.
$service = new FixtureService();

// Fetch the DataObject that you wish to generate a fixture for.
/** @var Page $page */
$page = Page::get()->byID(1);

// Check for warnings? This is somewhat important, because if you have looping relationships (which we have no way of
// creating fixtures for at the moment) this is how you'll know about it.
if (count($service->getWarnings() > 0) {
    Debug::dump($service->getWarnings());
}

// Do something with the fixture output.
highlight_string($service->outputFixture());

// Or maybe save the output to a file?
$fixture = Director::baseFolder() . '/app/resources/fixture.yml';
file_put_contents($fixture, $service->outputFixture());
```

## Excluding classes from export

There might be some classes (like Members?) that you don't want to include in your fixture. The manifest will check
classes for the existence (and truth) of the config variable `exclude_from_fixture_relationships`.

You can set this in a yml file:
```yaml
SilverStripe\Security\Member:
  exclude_from_fixture_relationships: 1

SilverStripe\Security\Group:
  exclude_from_fixture_relationships: 1

SilverStripe\Security\MemberPassword:
  exclude_from_fixture_relationships: 1

SilverStripe\Security\RememberLoginHash:
  exclude_from_fixture_relationships: 1
```

The above examples have been set in _config/model.yml. If you wish to override them, you can also do so by adding your
own yml config "After" `dataobjecttofixturemodel`. EG:

```yaml
Name: my_dataobjecttofixturemodel
After: dataobjecttofixturemodel
---
SilverStripe\Security\Member:
  exclude_from_fixture_relationships: 0
```

## Supported relationships

- `has_one`
- `has_many`
- `many_many` where a `through` relationship has been defined.

## Unsupported relationships

- `many_many` where **no** `through` relationship has been defined (you should be using `through`.... Use `through`).
- `has_one` relationships that result in a loop of relationships.

## Fluent support

It is my intention to support Fluent and exporting Localised fields.

Current state *does* support this, but, again, this is still very early development, so you should be validating
everything before you just go right ahead and assume it's perfect.

## Future features

- Add the option/ability to store binary files so that they can be restored with the fixture.
- Move to using ArrayList instead of arrays (probably). 
- Let me know what else you'd like!
