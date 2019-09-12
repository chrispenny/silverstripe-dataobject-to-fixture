# Change Log

## [1.0.0](https://github.com/silverstripe-terraformers/silverstripe-embargo-expiry/releases/1.0.0) (13 March 2019)

### NON BACKWARDS COMPATIBLE CHANGE

The following permissions have had their names changed. The reason for this change is that (I didn't realise) any permission name with the prefix of `CMS_ACCESS_` will be accepted if the user has the permission of `CMS_ACCESS_LeftAndMain` (see `Permission::checkMember()`).

```
CMS_ACCESS_AddEmbargoExpiry
CMS_ACCESS_RemoveEmbargoExpiry

// Are now:
AddEmbargoExpiry
RemoveEmbargoExpiry
```

When you upgrade to `1.0.0`, if you have user groups other than just `ADMIN`, you will need to update those user groups to have these new permissions.

## NON BACKWARDS COMPATIBLE CHANGE - METHOD NAME CHANGES

`EmbargoExpiryExtension`:
`addEmbargoExpiryNoticeFields` is now `addNoticeOrWarningFields`.
`addPublishingScheduleFields` is now `addDesiredDateFields`.
`addPublishingScheduleMessageFields` is now `addScheduledDateFields`.

### Bugfix

**Publish** button was not being removed when the page was reloaded with an existing `PublishOnDate`.

### Change

 * Reduce complexity of EmbargoExpiryExtension methods.
 * Added test coverage for EmbargoExpiryFluentExtension.
 * Update linting and tests for Extensions.
 * Updated doc blocks and conditional statements in Jobs.
 * Update user docs.
 * Added .gitattributes.
 * Add license/contribution/changelog/etc docs.
 * Scrutinizer fixes.
 * Improved readability of JS.
 * Renamed `config.yml` to `extensions.yml` and added the `CMSMain` extension by default.
 * Updated README.

## [v1.0.0 Release Candidate 4](https://github.com/silverstripe-terraformers/silverstripe-embargo-expiry/releases/v1.0.0-rc4) (15 Feb 2019)

 * Remove PHP 5.6 support
 * Increase test coverage to 78% (still more to come)
 * Bug: EmbargoExpiryGridFieldItemRequestExtension Actions are readonly if object isn't editable

## [v1.0.0 Release Candidate 3](https://github.com/silverstripe-terraformers/silverstripe-embargo-expiry/releases/v1.0.0-rc3) (25 Sep 2018)

### Security patch

Don't return `true` from `can..()` methods, instead return `null` so that we fall back to `SiteTree` permission checks for `true` criteria.

## [v1.0.0 Release Candidate 2](https://github.com/silverstripe-terraformers/silverstripe-embargo-expiry/releases/v1.0.0-rc2) (14 Sep 2018)

### Fluent
Added an Extension that can be used with DataObjects that implement `FluentVersionedExtension`.

Fluent is not a dependency for this module, as such, no unit tests are provided.

### Advanced workflow
In preparation for Advance Workflow splitting out their Embargo/Expiry feature:

 * Added "DesiredPublishDate"
 * Added "DesiredUnPublishDate"
 * Fixed a tonne of linting
 * Added a separate UnPublishTargetJob
 * Added some more extension points
 * Updated some logic to support the idea of a user setting a date, but it not being used immediately

### Job queue
Added some extension points so that devs can change what queue type is used for their jobs.

### Test coverage
Added job processing to test coverage.

## [v1.0.0 Release Candidate](https://github.com/silverstripe-terraformers/silverstripe-embargo-expiry/releases/v1.0.0-rc1) (23 Jul 2018)

 * Initial POC code release
 * No support for Workflow
