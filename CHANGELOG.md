# Changelog


## 1.0.4 (October 6, 2022)

### Added
- `allSets` option added which disables automatic completion of variation/set generation if the set dimension is greater than that of the original image.


## 1.0.3 (July 27, 2022)

### Added
- A note in the README about the `<picture>` element


## 1.0.2 (September 7, 2021)

### Added
- `useSrcUrlOnSize` option to render(). If enabled, a webP image larger than the original will disable PageimageSource's use of webP for this image, which in turn disables the `picture` option.


## 1.0.1 (September 7, 2021)

### Added
- Disable option to render():  `$pageimage->render('<img src="{src}" alt="{alt}">', false);`


## 0.1.0 (April 7, 2021)

### Added
- Started module development
