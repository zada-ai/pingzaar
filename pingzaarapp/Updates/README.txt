Update packages
===============

Each folder here is named for the upgrade it performs, e.g.

    v3.9.8-fix-102525 to v3.10.0/

and contains:

    update.zip                  the package to upload

To apply an update, sign in as an administrator and go to

    Admin -> System -> Updates

and upload that folder's update.zip. Do not unzip it first.

Language files are not part of an update -- your installed languages are your
own copy and are never overwritten. To pick up text a release adds, download
the latest files from

    https://github.com/titansys/zender-languages

and re-upload them under Administration -> Languages. If you have customised a
language, do not overwrite it: that repository's "changes" folder lists the keys
each version adds, so you can top your own translation up without losing edits.

Upgrading from a version older than the oldest folder here? Download that
release's own ZIP from the releases page and apply its update packages in
order first.
