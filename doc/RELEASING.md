# Releasing

The omeka.org addon registry reads GitHub releases. Its requirements, from
<https://omeka.org/s/docs/developer/register_an_addon/>:

- the release is published (not a draft, not a prerelease);
- it has a `.zip` attached as a binary asset — **not** GitHub's auto-generated
  source archive, which has the wrong top-level directory name;
- that ZIP contains exactly one top-level directory, named for the registered
  addon: `CollectionTreeSelect`;
- `/plugin.ini` parses, has an `[info]` section and a `version` field;
- the README is `README.md`.

Once the addon is registered, later releases following the same steps are picked
up automatically.

## Steps

1. Bump the version in **two** places — they are deliberately separate, since the
   asset cache-buster should also change when only CSS or JS moves:
   - `plugin.ini` → `version=`
   - `CollectionTreeSelectPlugin.php` → `ASSET_VERSION`

2. Run the tests (see `test/README.md`):

   ```bash
   php test/test-tree.php
   ```

3. Commit and tag:

   ```bash
   git commit -am "Release 1.3.1"
   git tag -a v1.3.1 -m "Release 1.3.1"
   git push origin main --tags
   ```

4. Build the archive. `--prefix` sets the single top-level directory, and
   `.gitattributes` keeps `test/`, `doc/` and the dotfiles out:

   ```bash
   git archive --format=zip --prefix=CollectionTreeSelect/ -o CollectionTreeSelect-1.3.1.zip v1.3.1
   ```

5. Check it before uploading — one top-level directory, `plugin.ini` present, no
   `test/`:

   ```bash
   unzip -l CollectionTreeSelect-1.3.1.zip | head -20
   ```

6. Draft a release on the tag, attach the ZIP, publish.

7. First release only: register the addon at
   <https://omeka.org/add-ons/submit/>.

Setting a release back to draft or prerelease removes it from the registry.
