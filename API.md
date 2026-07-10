# PageimageSource - Agent API Reference

Machine-oriented reference for the `PageimageSource` module and its bundled
`TextformatterPageimageSource`. For human-oriented setup/usage docs and
narrative examples, see `README.md` in this same directory. For version
history see `CHANGELOG.md`.

This file documents the *actual code-level API surface* an agent needs to
generate correct template/module code against this module: hooked methods,
property names, option keys, defaults, return types, and known gotchas.

## Module identity

- Class: `PageimageSource` (file `PageimageSource.module`)
- Config class: `PageimageSourceConfig` (file `PageimageSourceConfig.php`, extends `ModuleConfig`)
- Bundled module: `TextformatterPageimageSource` (file `TextformatterPageimageSource.module`)
- `autoload`: `template!=admin`
- `singular`: true
- Requires: ProcessWire >= 3.0.165, PHP >= 7.3

## Module config properties

Accessed either via `$modules->get('PageimageSource')->propertyName` or,
since the hooks are added by this module instance, indirectly through
`Pageimage::render()`/`Pageimage::srcset()` default behaviour.

| Property | Type | Default | Notes |
| --- | --- | --- | --- |
| `defaultSets` | string | (none, required) | Newline or comma-delimited srcset rules, see "Srcset rule syntax" below. Required for module to function - `init()` will add a config error and the hooks below will use an empty ruleset if unset. |
| `allSets` | bool | `0` | If true, all configured set rules are always used regardless of whether they are smaller than the source image (implies `upscaling => true` when passed through to `getSrcset`/`size()`). |
| `webp` | bool | `0` | Enables WebP generation/use in `srcset()` and `render()`. |
| `webpQuality` | int | `90` | Passed to `$config->webpOptions('quality', ...)` when webp enabled. |
| `useLazy` | bool | `1` | Default for the `lazy` render option. |
| `usePicture` | bool | `0` | Default for the `picture` render option. Requires `webp` to be enabled to have effect. |
| `removeVariations` | bool | n/a | Not a persisted setting - a one-shot admin action checkbox processed in `init()` on module config form submit. Recursively deletes files matching `*-srcset.*` under `$config->paths->files`. |

## Constant

- `PageimageSource::suffix` = `'srcset'` - suffix appended to generated variation filenames (e.g. `image.320x0-srcset.jpg`). Used to identify/remove module-generated variations.

## Hooked Pageimage API

The module hooks these onto `Pageimage` (both as callable **methods** and as
**properties**, in four case variants - all four resolve to the same handler
`___getImageSrcset()`, differing only in which image URL property is used):

| Hook name | Returned URL property used |
| --- | --- |
| `Pageimage::srcset` | `webpUrl` if module `webp` enabled, else `url` |
| `Pageimage::httpSrcset` | `httpUrl` |
| `Pageimage::SRCSET` | `URL` |
| `Pageimage::HTTPSRCSET` | `HTTPURL` |

### `$pageimage->srcset` (property)

Returns a string using the module's configured `defaultSets` and `allSets`/`webp` settings. Equivalent to `$pageimage->srcset()` with no arguments.

```php
$srcset = $image->srcset;
```

### `$pageimage->srcset($srcset = null, array $options = [])` (method)

- `$srcset` (string|array|null): srcset rule definition. If omitted/invalid type, falls back to the module's configured `defaultSets`.
  - String form: one rule per line (newlines or commas as separators), e.g. `'320, 480, 640x480 768w, 1024, 2048 2x'`.
  - Sequential array form: same syntax, one rule per array element.
  - Associative array form (`rule-name => [width, height]`): bypasses all rule parsing/validation entirely - used as-is. Keys become the `w`/`x` descriptor string in the output (must include the `w`/`x` suffix yourself, e.g. `'320w' => [320, 0]`).
- `$options` (array): merged over defaults:
  - `allSets` (bool) - default from module config.
  - `suffix` (string) - default `PageimageSource::suffix`.
  - `upscaling` (bool) - default `false`. Forced `true` internally if `allSets` resolves `true`.
  - `webpAdd` (bool) - default from module config `webp`.
  - `webpQuality` (int) - default from module config `webpQuality` if set and not already present in `$options`.
  - Any other option accepted by `Pageimage::size()` (this is passed straight through as `$image->size($w, $h, $options)`), e.g. `hidpi`.
- Returns: string, e.g. `'image.320x0-srcset.jpg 320w, image.640x0-srcset.jpg 640w'`, or `''` if no sets could be generated.

**Behaviour notes (important for correctness when generating code):**
- A set rule is only used if the source image is actually larger than it in the relevant dimension, unless `upscaling`/`allSets` is enabled - so `srcset()` output size varies per-image and is not deterministic purely from config.
- Rule generation stops (`break`) at the first rule that is >= the source image dimensions, *unless* `options['allSets']` is true, in which case all rules are included.
- Width-only rules (e.g. `320`) call `$image->size($w, 0, $options)`; height-only rules call `$image->height($h, $options)`; both call `$image->size($w, $h, $options)`.
- If called on an existing image *variation* (not the original), the module resolves the original file and works out correct width/height/ratio to size from, rather than upscaling the variation itself.

### `getSrcset()` string rule syntax

Format per line: `{width}x{height} {inherentwidth}w|{resolution}x` — only `width` (or only `height`, via `x{height}`) is required.

| Rule | Meaning |
| --- | --- |
| `320` | width=320, height=0 (proportional), descriptor `320w` |
| `480x540` | width=480, height=540, descriptor `480w` |
| `640x480 768w` | width=640, height=480, but descriptor forced to `768w` |
| `2048 2x` | width=2048, height=0, descriptor `2x` |

Invalid lines (bad ints, >2 space-separated parts, >2 `x`-separated dimensions, or a second part that is neither a `w` nor `x` suffix, or both dimensions zero) are skipped and surfaced via `$this->error()` - they do not throw.

### `PageimageSource::getSets($sets = null)` (public method, on module instance)

`$modules->get('PageimageSource')->getSets()` - returns the parsed rule array (`rule => [width, height]`) currently active, or for a given `$sets` input. Primarily used internally by `PageimageSourceConfig` to render the config-screen preview; useful for agents wanting to introspect the effective ruleset without generating images.

## Hooked `Pageimage::render()`

`PageimageSource` hooks `after Pageimage::render` and rewrites the returned markup. Signature is unchanged: `render($markup = null, $options = [])` or `render($options = [])`.

Options merged over PW core defaults:

| Option | Type | Default | Description |
| --- | --- | --- | --- |
| `srcset` | bool\|string\|array | `true` | `false` disables srcset/sizes attributes entirely. String/array forms as per `srcset()` above. Advanced form: `['rules' => ..., 'options' => [...]]` to pass both rules and `srcset()` options together. |
| `sizes` | string\|array | `'100vw'` | Array form is imploded with `', '`. |
| `lazy` | bool | module `useLazy` config | `true` adds `loading="lazy"`; `false` suppresses it even if enabled in config. |
| `picture` | bool\|string | module `usePicture` config | `true` wraps output in `<picture>` with WebP/source `<source>` elements (requires `webp` enabled - forced `false` otherwise). A string starting with `<picture` is used verbatim as the opening tag (to add custom attributes). |
| `useSrcUrlOnSize` | bool | `false` | If true and the current webp URL isn't already a `.webp` file, disables webp for this render call only. |
| `width` / `height` | int | - | If either is set, the image is resized via `$image->size()` before rendering (in addition to/independent of srcset). |

**Disabling all module augmentation for a single render call:**

```php
// Pass boolean false as the sole/second argument to bypass this module's
// hook logic entirely for this call and get plain core Pageimage::render() output.
echo $image->render(false);
echo $image->render($markup, false);
```

Internally this simply returns without modifying the returned markup, and
does **not** mutate the module's `useLazy`/`usePicture`/`webp` instance
properties. Note: in older/unpatched copies of this module, calling
`render(false)` anywhere in a request would permanently disable lazy
loading, `<picture>`, and WebP for all subsequent `render()` calls in that
same request — see CHANGELOG for details if working with an older version.

Non-JPEG/PNG images (anything not `jpeg`/`jpg`/`png`) never get WebP applied regardless of config.

## `TextformatterPageimageSource`

- Requires `PageimageSource` to be installed (`'requires' => 'PageimageSource'` in module info).
- Apply to a text/textarea field via Setup > Fields > (field) > Details > Text Formatters.
- Scans rendered field HTML for `<img src="{files-url}...">` matching `jpeg|jpg|png`, resolves the owning `Page` and `Pageimage` from the URL/filename (including already-resized variation filenames like `example.300x0-is-hidpi.jpg`), then re-renders that image through `Pageimage::render()` (thus gaining srcset/webp/lazy/picture per current module config) while preserving the original `<img>` tag's other attributes and adding `data-width`/`data-height` with the original (unresized) dimensions.
- Per-page/per-field results are cached (`$this->replacements[$cacheKey]`) for the duration of a single `formatValue()` cursor (i.e. while formatting fields for the same page id back-to-back).
- Has no configuration of its own - all rendering behaviour is inherited from `PageimageSource`'s module config at request time.

## Known gotchas for agents generating code against this module

1. Do not assume `srcset()`/`render()` will produce sets for images smaller than all configured rules — the smallest generated set is always constrained by the actual source image dimensions unless `allSets`/`upscaling` is explicitly enabled.
2. `defaultSets` must be configured (non-empty) for the module's own default behaviour to work; passing explicit rules to `srcset()`/`render()` still works even if `defaultSets` is empty, but produces a config-screen validation error if left empty when saving module settings.
3. Associative-array `srcset` values bypass all validation - malformed entries here will not raise `$this->error()` warnings, unlike string/sequential-array forms.
4. `render()`'s `picture` option silently downgrades to `false` if `webp` is not enabled module-wide, even if you pass `'picture' => true` explicitly.
5. The "Remove Variations" admin action deletes any file anywhere under `$config->paths->files` matching `*-srcset.*` — this is a destructive, non-reversible bulk operation and is unrelated to the image field/page it may have been generated from.