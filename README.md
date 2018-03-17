# Imposer: Modular State and Configuraton Management for Wordpress

Storing configuration in a database *sucks*.  You can't easily document it, revision control it, reproduce it, or vary it programmatically.

Unfortunately, Wordpress doesn't really give you many alternatives.  While some WP configuration management tools exist, they typically lack one or more of the following:

* Twelve-factor support (e.g. environment variables for secrets, keys, API urls, etc.)
* Modularity (ability to bundle states as, or with plugins, wp-cli packages, or composer libraries)
* Composability (ability to spread configuration across multiple files, even when the values are settings for the same plugin, page, menu, etc.)
* Scriptability and metaprogramming (ability to use PHP or other scripting languages to decide how to generate data based on other aspects of the configuration, or external files, env vars, etc.)

Imposer is a command-line tool (for \*nix-type OSes) that does all of these things using "state" files.

State files work a bit like "[migrations](https://en.wikipedia.org/wiki/Schema_migration)" or [Drupal's "features"](https://www.drupal.org/project/features).  They're markdown documents whose names end in `.state.md`, and can contain code blocks written in various languages, including YAML, JSON, shell script, PHP, and [jq](http://stedolan.github.io/jq/).

These states can be distributed as part of Wordpress plugins, composer or wp-cli packages, or simply placed in any directory listed by an `IMPOSER_PATH` environment variable.  States can be applied on the command line, and those states can in turn `require` other states.  The YAML, JSON, shell and jq blocks are executed first, in order, to create an overall JSON configuration that is passed to the combined PHP code supplied by all the loaded states, and run via [`wp eval-file`](https://developer.wordpress.org/cli/commands/eval-file/).

## Installation, Requirements, and Use

Imposer is packaged with composer, and is intended to be installed that way, i.e. via `composer require dirtsimple/imposer` or `composer global require dirtsimple/imposer`.  In either case, make sure that the appropriate `vendor/bin` directory is on your `PATH`, so that you can just run `imposer` without having to specify the exect location.

In addition to PHP, Composer, and Wordpress, imposer requires:

* [jq](http://stedolan.github.io/jq/) 1.5 or better (and if you're on Windows, it must be the *Cygwin* version, not the Windows version)
* the PECL extension for YAML (or [yaml2json](https://github.com/bronze1man/yaml2json), or Python with PyYAML)
* the bash shell, version 3.2 or better

Imposer is not yet regularly tested on anything other than Linux, but it *should* work on Cygwin, OS/X, and other Unix-like operating systems with a suitable version of bash and jq.

To use Imposer, you must have either a `composer.json` or `wp-cli.yml` file located in the root of your current project.  Imposer will search upward from the current directory for one or the other before running, and all relative paths (e.g. those in `IMPOSER_PATH`) will be treated as relative to that directory.  (And all code in state files is executed with that directory as the current directory.)

Basic usage is `imposer require` *statenames...*, where each state name designates a state file to be loaded.  States are loaded in the order specified, unless an earlier state `require`s a later state, forcing it to be loaded earlier than its position in the list.

For convenience, state names do not include the `.state.md` suffix, and can also just be the name of a composer package (e.g. `foo/bar`) or plugin directory (e.g. `someplugin`).  Given a string such as `foo/bar/baz`, imposer will look for the following files in the following order, in each directory on `IMPOSER_PATH`, with the first found file being used:

* `foo/bar/baz.state.md` (the exact name, as a `.state.md` file)
* `foo/bar/baz/default.state.md` (the exact name as a directory, containing a `default.state.md` file)
* `foo/bar/baz/imposer/default.state.md` (the exact name as a directory, containing an `imposer/default.state.md` file)
* `foo/bar/imposer/baz.state.md` (the namespace of the name as a directory, containing the last name part in an `imposer` subdirectory)

(The last rule means that you can create a composer package called e.g. `mycompany/imposer` containing a lirbrary of state files, that you can then require as `mycompany/foo` to load `foo.state.md` from `vendor/mycompany/imposer`.  Or you can make a Wordpress plugin called `myplugin`, and then require  `myplugin/bar` to load `bar.state.md` from the plugin's `imposer/` directory.)

The default `IMPOSER_PATH` contains:

* `./imposer`
* The Wordpress themes directory as provided by wp-cli (e.g. `wp-content/themes/`)
* The Wordpress plugin directory as provided by wp-cli (e.g. `wp-content/plugins/`)
* The `COMPOSER_VENDOR_DIR` (e.g. `vendor/`)
* The wp-cli package path, as provided by wp-cli (typically `~/.wp-cli/packages`)
* The global composer `vendor` directory, e.g. `${COMPOSER_HOME}/vendor`

This allows states to be distributed and installed in a variety of ways, while still being overrideable at the project level (via the `imposer/`) directory.

## State Files

### Syntax

### YAML and JSON Data

### Shell and JQ Code

### PHP Code

### Distribution and Reuse

## Project Status

Currently, this project is in *extremely* early development, as in it doesn't have automated tests yet, nor does it explicitly support any type of state other than Wordpress options.  (But they can still be implemented by adding arbitrary shell or PHP code to your state files.)

### Performance Notes

While imposer performance is not generally critical, you may be running it a lot during development, and a second or two of run time can add up quickly during rapid development.  If you are running it with a lot of states, you may wish to note that:

* Currently, calculating the default `IMPOSER_PATH` is slow because it runs `wp` and `composer` twice each.  You can speed this up considerably by supplying an explicit `IMPOSER_PATH`.  (You can run `imposer path` to find out the the directories imposer is currently using, or `imposer default-path` to get the directories imposer would use if `IMPOSER_PATH` were not set.)
* YAML blocks are processed considerably slower than JSON blocks, since external programs are used to translate them into JSON for jq.  (This is *especially* slow if Python or PHP are used, due to slow startup times.)  You can speed this up a bit by installing  [yaml2json](https://github.com/bronze1man/yaml2json), or by using JSON blocks instead.  (Note: if caching is enabled, this will only speed up the processing of new or just-changed state files, or building with an empty or invalid cache.)
* By default, the compiled version of state files are cached in `imposer/.cache` in your project root.  You can change this by setting `IMPOSER_CACHE` to the desired directory, or an empty string to disable caching.
* wp-cli commands are generally slow to start: if you have a choice between running wp-cli from a shell block, or writing PHP code directly, the latter is considerably faster.

