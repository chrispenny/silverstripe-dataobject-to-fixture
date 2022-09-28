# Silverstripe DataObject To Fixture

Generate a YAML fixture from DataObjects
- [Installation](#installation)
- [Purpose (early stage)](#purpose-(early-stage))
- [Purpose (future development)](#purpose-(future-development))
- [General usage](#general-usage)
  - [Warnings](#warnings)
  - [Dev task](#dev-task)
- [Excluding relationships from export](#excluding-relationships-from-export)
- [Excluding classes from export](#excluding-classes-from-export)
- [Common issues](#common-issues)
  - [Parent Pages included in your export](#parent-pages-included-in-your-export) 
  - [Node `[YourClass]` has `[x]` left over dependencies, and so could not be sorted](#node-yourclass-has-x-left-over-dependencies-and-so-could-not-be-sorted) 
  - [Request timeout when generating fixtures](#request-timeout-when-generating-fixtures) 
  - [DataObject::get() cannot query non-subclass DataObject directly](#dataobjectget-cannot-query-non-subclass-dataobject-directly) 
- [Supported relationships](#supported-relationships)
- [Fluent support](#fluent-support)
- [Future features](#future-features)
- [Things that this module does not currently do](#things-that-this-module-does-not-currently-do)

## Installation

```
composer require chrispenny/silverstripe-data-object-to-fixture
```

## Purpose (early stage)

The purpose of this module (at this early stage) is not to guarantee perfect fixtures every time, but more to provide a
solid guideline for what your fixture should look like.

For example:
Writing unit test fixtures can be difficult, especially when you're needing to visualise the structure and relationships
of many different DataObjects (and then add an extra layer if you're using, say, Fluent).

I would also like this module to work well with the
[Populate](https://github.com/silverstripe/silverstripe-populate) module. Please note though that you'll need to be
running version [2.1 or greater](https://github.com/silverstripe/silverstripe-populate/releases/tag/2.1.0), as versions
before that did not support circular relationships.

## Purpose (future development)

My dream for this module is that I would like to get to a stage where we can confidently say that generated fixtures
will be perfect every time.

From there, I could see this being used (as an example) for testers to be able to export pages through the CMS on their
test environments, so that those pages can then be restored at any time via (maybe) Populate. How this would work
exactly, and whether or not it would use Populate, is still to be determined.

## Warnings

This is still in early development stages. Please be aware that:

- Classes might change
- Return types might change
- Entire paradigms on how I generate the fixtures might change

What won't change:

- The public API will not change. There will still be a service with those 3 main methods.

I would not recommend that you use this module (at this stage) for any application critical features, but I **would**
recommend that you use it as a developer tool (EG: to help you write your own fixtures, either for tests, or to be used
with Populate).

## General usage

### Dev task

A dev task can be found at `/dev/tasks/generate-fixture-from-dataobject`.

This task will allow you to generate a fixture (output on the screen for you to copy/paste) for any DataObject that
you have defined in your project.

### Code use

```php
// Instantiate the Service.
$service = new FixtureService();

// Fetch the DataObject that you wish to generate a fixture for.
/** @var Page $page */
$page = Page::get()->byID(1);

// Add the DataObject to the Service.
$service->addDataObject($dataObject);

// Generating the fixture can also generate new warnings
$output = $service->outputFixture();

// Check for warnings? This is somewhat important, because if you have looping relationships (which we have no way of
// creating fixtures for at the moment) this is how you'll know about it.
if (count($service->getWarnings()) > 0) {
    Debug::dump($service->getWarnings());
}

// Do something with the fixture output.
highlight_string($output);

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

## Excluding relationships from export

Similar to excluding classes, there might be some specific relationships on specific classes that you want to exclude.
Perhaps you have identified a looping relationship, and you would like to exclude one of them to make things
predictable, or perhaps it's just a relationship you don't need in your fixtures.

You can exclude specific relationships by adding `excluded_fixture_relationships` to the desired class.

`excluded_fixture_relationships` accepts an array of **relationship names**.

EG:
```php
class MyModel extends DataObject
{
    private static $has_one = [
        'FeatureImage' => Image::class,
    ];
}
```

```yaml
App\Models\MyModel:
  excluded_fixture_relationships:
    - FeatureImage
```

## Common issues

### Parent Pages included in your export

When you're exporting Pages, if that Page has a `Parent`, then that `Parent` is considered a valid relationship, and
so it will get exported along with the Page you've selected.

I'm still considering what to do about this, but for now, I would probably recommend that you add `Parent` to the list
of excluded relationships for `SiteTree`:

```yaml
SilverStripe\CMS\Model\SiteTree:
    excluded_fixture_relationships:
        - Parent
```

### Node `[YourClass]` has `[x]` left over dependencies, and so could not be sorted

This generally happens when you have a looping relationship. EG: `Page` `has_one` `Link`, and `Link` `has_one` back to
`Page`. The sorter cannot determine which class should be prioritised above the other.

This doesn't necessarily mean that things will break, but it's worth reviewing. You might find that you can exclude one
of the relationships in order to make thing more consistent.

A good example of this is in Elemental. Elemental provides an extension called `TopPage` which provides a relationship
directly from each `BaseElement` to the `Page` that it belongs to (it's like a "index" so that you can loop up your
`Page` from the `BaseElement` with less DB queries). This is handy for developers, but less handy for YAML fixtures.
We'd actually prefer to exclude this relationship and follow the correct relationship flow from `Page` to
`ElementalArea` to `BaseElement`.

I could exclude this relationship by adding the following configuration:
```yaml
DNADesign\Elemental\Models\BaseElement:
  excluded_fixture_relationships:
    - TopPage
```

### Request timeout when generating fixtures

Above are two options that you can use to attempt to reduce this.

- [Excluding relationships from export](#excluding-relationships-from-export)
- [Excluding classes from export](#excluding-classes-from-export)

I would recommend that you begin by exluding classes that you don't need for your export, then move to excluding
specific relationships that might be causing deep levels of nested relationships.

### DataObject::get() cannot query non-subclass DataObject directly

You might see this error if you have polymorphic relationships (relationships being defined as simply
`DataObject::class`), EG:

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
- `many_many` (with and without `through` definitions)

## Fluent support

It is my intention to support Fluent and exporting Localised fields in the future, but at this time, there is no
support provided.

## Future features

- Add the option/ability to store binary files so that they can be restored with the fixture.
- Let me know what else you'd like!

## Things that this module does not currently do

- Export `_Live` tables. I hope to add `_Live` table exports soon(ish).
- Support for exporting/saving away Asset binary files has not been added. This means that in the current state, you can
only generate the database record for an Asset.
