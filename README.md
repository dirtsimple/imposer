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

For convenience, state names do not include the `.state.md` suffix, and can also just be the name of a composer package (e.g. `foo/bar`) or plugin directory (e.g. `someplugin`).  Given a string such as `foo/bar`, imposer will look for the following files in the following order, in each directory on `IMPOSER_PATH`:

* `foo/bar.state.md`
* `foo/bar/bar.state.md`
* `foo/bar/default.state.md`
* `foo/bar/imposer/bar.state.md`
* `foo/bar/imposer/default.state.md`

The first file found is used.  The default `IMPOSER_PATH` contains:

* `./imposer`
* The Wordpress plugin directory as provided by  wp-cli (e.g. `plugins/`)
* The `COMPOSER_VENDOR_DIR` (or `vendor` if not specified)
* The wp-cli package path, as provided by wp-cli (typically `~/.wp-cli/packages`)
* The global composer `vendor` directory, e.g. `${COMPOSER_HOME}/vendor`


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
* YAML blocks are processed considerably slower than JSON blocks, since external programs are used.  (This is *especially* slow if Python or PHP are used, due to slow startup times.)  You can speed this up by installing  [yaml2json](https://github.com/bronze1man/yaml2json), or by using JSON blocks instead.  (A future version of imposer will provide the option to cache the compiled version of each `.state.md` file, so that YAML to JSON conversion overhead only happens on the first use of a new or modified state file.)
* wp-cli commands are generally slow to start: if you have a choice between running wp-cli from a shell block, or writing PHP code directly, the latter is considerably faster.

