# silverstripe-data-object-to-fixture

Generate a YAML fixture from DataObjects

- [Purpose (early stage)](#purpose-(early-stage))
- [Purpose (future development)](#purpose-(future-development))
- [Warnings](#warnings)
- [Dev task](#dev-task)
- [General usage](#general-usage)
- [Excluding classes from export](#excluding-classes-from-export)
- [Common errors](#common-errors)
- [Supported relationships](#supported-relationships)
- [Unsupported relationships](#unsupported-relationships)
- [Fluent support](#fluent-support)
- [Future features](#future-features)
- [Things that this module does not currently do](#things-that-this-module-does-not-currently-do)

## Purpose (early stage)

The purpose of this module (at this early stage) is not to create perfect fixtures, but more to provide a solid
guideline for what your fixture should look like.

For example:
Writing unit test fixtures can be difficult, especially when you're needing to visualise the structure and relationships
of many different DataObjects (and then add an extra layer if you're using, say, Fluent).

## Purpose (future development)

One goal for the future of this module is for it to work in tandem with the Populate module. I would like to get to a
stage where content authors are able to (for example) "export pages" through the CMS, so that those pages can then be
recreated via Populate (without a dev needing to create the fixture themselves).

## Warnings

This is in very, very early development stages. Please be aware that:

- Namespaces might change
- Classes might change
- Entire paradigms on how I generate the fixtures might change

What won't change:

- The public API will not change. There will still be a service with those 3 main methods.

I would not recommend that you use this module (at this stage) for any application critical features, but I **would**
recommend that you use it as a developer tool (to help you write your own fixtures, either for tests, or to be used
with Populate).

## Dev task

A dev task can be found at `/chrispenny/silverstripe-data-object-to-fixture`.

This task will allow you to generate a fixture (output on the screen for you to copy/paste) for any DataObject that
you have defined in your project.

## General usage

```php
// Instantiate the Service.
$service = new FixtureService();

// Fetch the DataObject that you wish to generate a fixture for.
/** @var Page $page */
$page = Page::get()->byID(1);

// Add the DataObject to the Service.
$service->addDataObject($dataObject);

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

## Common errors

### DataObject::get() cannot query non-subclass DataObject directly

You might see this error if you have a relationship being defined as simply `DataObject::class`, EG:

```php
private static $has_one = [
    'Parent' => DataObject::class,
];
```

This module needs to know what `DataObject` it is querying for. Modules like Userforms do this because you can
technically have any Model as the parent. These modules do, however, store the class name for this relationship in
a separate field so that we are able to query for the parent appropriately. For Userforms, the class name for this
relationship is stored in a field called `ParentClass`. This module doesn't know that though.

You can tell this module where that class name information lives for any relationship by using the following
configuration (this is using Userforms as the example):

```php
SilverStripe\UserForms\Model\EditableFormField:
  field_classname_map:
    ParentID: ParentClass
```

`field_classname_map` is the config we want to populate, and it expects an array with the relationship field name as the
`key`, and the corresponding class name field as the `value`.

The module uses `relField()` with the `value`, so you could be presenting this information through a data field, or a
method.

## Supported relationships

- `has_one`
- `has_many`
- `many_many` where a `through` relationship has been defined.

## Unsupported relationships

- `many_many` where **no** `through` relationship has been defined (you should be using `through`.... Use `through`).
- `has_one` relationships that result in a loop of relationships (`belongs_to` is the "backward" definition for a
`has_one` relationship, unfortunately, this is not currently supported in fixtures, so, we have no way to create a
fixture for relationships that loop).

## Fluent support

It is my intention to support Fluent and exporting Localised fields.

Current state *does* support this, but, again, this is still very early development, so you should be validating
everything before you just go right ahead and assume it's perfect.

## Future features

- Add the option/ability to store binary files so that they can be restored with the fixture.
- Let me know what else you'd like!

## Things that this module does not currently do

- Export `_Live` tables. I hope to add `_Live` table exports soon(ish).
- There is no ordering logic for records within the same class. This means that if you have classes that can have
relationships to itself, the order or records might not be correct.
- Support for exporting/saving away Asset binary files has not been added. This means that in the current state, you can
only generate the database record for an Asset.
