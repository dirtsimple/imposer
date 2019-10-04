# Imposer: Modular State and Configuration Management for Wordpress

Storing configuration in a database *sucks*.  You can't easily document it, revision control it, reproduce it, or vary it programmatically.

Unfortunately, Wordpress doesn't really give you many alternatives.  While some configuration management tools exist, they typically lack one or more of the following:

* Twelve-factor support (e.g. environment variables for secrets, keys, API urls, etc.)
* Modular Distribution (ability to include states as part of plugins, wp-cli packages, or composer libraries)
* Incrementality/Composability (ability to spread configuration across multiple files, even when the values are settings for different aspects of the same plugin, page, menu, etc.)
* Scriptability and metaprogramming (ability to use PHP or other scripting languages to decide how to generate data based on other aspects of the configuration, or external files, environment vars, etc.)

And while wp-cli is great for *making changes* to the Wordpress database, this is not quite the same thing as *imposing state* on a Wordpress database.

What's the difference?  Well, with wp-cli you can (for example), use  `wp menu list` to check if a menu exists, and then `wp menu create` to create it if it doesn't.  But there's no way to give wp-cli a *list* of menus that you want to have exist, and with what items, and then have it automatically add or delete or move things around to make it match.  There's no way to give wp-cli a *specification* for how you want things to be.

That's what imposer's for.

Imposer is a command-line tool (bash+PHP) that lets you create and use modular, scriptable "state modules": files that define a *specification* for how you want some subset of things in your Wordpress instance to be.

A state module is a bit like a [Drupal "feature"](https://www.drupal.org/project/features), in that it specifies "things needed for a use case" as a reusable component.  For example, you might have a module that defines some ecommerce products and categories, along with some widgets to be placed in certain sidebars to link to those categories.  And perhaps another module that specifies the plugin you'll be using for SMTP email, and what credentials to use, based on environment variables.

These state modules can then be applied, separately or together, to as many Wordpress installations as you like.  They're files you can put in revision control, tweaking and tuning them against your development sites, and then applying them to your production sites to instantly deploy the latest version of some aspect of your site specifications.

You can have as many of these modules as you want, and they can depend on each other or override things that a previous one set, allowing you to effectively "subclass" aspects of sites from each other.  State modules can also be distributed as part of Wordpress plugins or themes, composer or wp-cli packages, or simply placed in any directory listed by an `IMPOSER_PATH` environment variable.  They can be applied as a one-off by listing them on the command line, or they can be part of your project-specific, user-specific, and/or system-wide configuration so that they're applied every time you run `imposer apply`.

Whenever you run `imposer apply`, the selected modules collectively create a JSON data structure called the **specification**, an object whose properties list things like the plugins that should be activated or disabled, the options (or parts of options) that should be set to what values, menus and menu items that should exist in what order and in what theme locations, and so on.  This specification is built dynamically each time you apply it, and can thus read environment variables, configuration files of your own design, connect to other systems to download information, or do *whatever else you want* to create the final specification.

(A specification doesn't have to define your entire database, though!  Even if you have multiple sites that need a common menu, that doesn't mean you have to specify *all* of those sites' menus via imposer.  Options, plugins, menus, posts, etc. that *aren't* part of the specification on a given run are generally not touched by imposer.)

A specification also doesn't define *how* its contents are mapped to the Wordpress database.  That's done by Imposer **tasks**.  Imposer supplies built-in tasks for [theme switching](#theme-switching), [plugin activation](#plugin-activation), [option patching](#option-patching), [menu/item definition](#menus-and-locations) and [menu location assignment](#menu-locations), as well as creating or updating [categories, tags, and other taxonomies' terms](#taxonomy-terms), [widget configuration and sidebar placement](#widgets-and-sidebars), but your modules can include PHP code to define other kinds of tasks as well.

(And any Wordpress plugins or wp-cli packages can do so, too!  For example, the [postmark wp-cli package](https://github.com/dirtsimple/postmark) provides [a state module that registers tasks](https://github.com/dirtsimple/postmark/blob/master/default.state.md) for importing posts, pages, and custom post types from Markdown files in directories listed by the specification.)

This separation of specification (content) and tasks (process) means that instead of writing a different wp-cli script for every site, you can write a generic task for configuring, say, a particular plugin's products or forms, and then reuse that task to apply different specifications on different sites... and even distribute it as part of a wp-cli package, Wordpress plugin, or theme!

State modules can be applied individually on the command line, or defined in a project configuration, and any of those states can `require` other states.  YAML, JSON, shell and jq blocks are executed first, in source order, to create a jq program that builds a JSON specification object representing the desired states of things in Wordpress.

And last -- but far from least -- your modules can also include "tweaks": PHP code that will be added to a dynamically-generated Wordpress plugin, as a modular, configurable, and theme-independent alternative to editing a theme's  `functions.php`.

### Contents

<!-- toc -->

- [User's Guide](#users-guide)
  * [How State Modules Work](#how-state-modules-work)
    + [Options, Theme, Plugins, and Dependencies](#options-theme-plugins-and-dependencies)
    + [Scripting The Specification](#scripting-the-specification)
    + [Processing Other Markdown Blocks](#processing-other-markdown-blocks)
    + [Identifying What Options To Set](#identifying-what-options-to-set)
    + [PHP Blocks](#php-blocks)
  * [Extending Imposer](#extending-imposer)
    + [Defining Tasks and Steps](#defining-tasks-and-steps)
    + [Distributing Modules and Extensions](#distributing-modules-and-extensions)
- [Reference](#reference)
  * [Installation, Requirements, and Use](#installation-requirements-and-use)
  * [Lookup and Processing Order](#lookup-and-processing-order)
    + [State Module to Filename Mapping](#state-module-to-filename-mapping)
    + [IMPOSER_PATH](#imposer_path)
    + [Processing Phases for `imposer apply`](#processing-phases-for-imposer-apply)
    + [State and Task Execution Order](#state-and-task-execution-order)
  * [Command-Line Interface](#command-line-interface)
    + [imposer apply *\[module...\] \[wp-cli option...\]*](#imposer-apply-module-wp-cli-option)
    + [imposer options](#imposer-options)
    + [imposer env](#imposer-env)
    + [Diagnostic Commands](#diagnostic-commands)
  * [Specification Schema](#specification-schema)
    + [Theme, Plugins, and Options](#theme-plugins-and-options)
    + [Menus and Locations](#menus-and-locations)
    + [Taxonomy Terms](#taxonomy-terms)
    + [Widgets and Sidebars](#widgets-and-sidebars)
  * [API](#api)
    + [Actions and Filters](#actions-and-filters)
    + [Event Hooks](#event-hooks)
    + [Option Filtering](#option-filtering)
  * [Project Status](#project-status)
    + [Performance Notes](#performance-notes)

<!-- tocstop -->

# User's Guide



## How State Modules Work

State modules are implemented as Markdown files whose names end in `.state.md`.  Imposer compiles and interprets these files using [mdsh](https://github.com/bashup/mdsh) (specifically, the [jqmd](https://github.com/bashup/jqmd) extension of mdsh), looking for triple-backquote fenced code blocks written in various languages, such as YAML, JSON, shell script, PHP, and [jq](http://stedolan.github.io/jq/).

Blocks in jq, YAML, and JSON are used to incrementaly build up the specification object, while shell script blocks let you load other modules, define and call functions, and add conditional logic or other scripting tasks to help compute the specification.  (For example, you could include some script to download some JSON via `curl` and then add it to the specification.)  PHP blocks are used to define additional imposer tasks, or to add runtime tweaks to Wordpress (like the snippets you'd normally add by editing `functions.php`).

Using Markdown as the file format for state modules offers many advantages besides the obvious one of including multiple languages in the same file.  Markdown files can include documentation as well as code, and can be automatically converted to nice-looking web pages with syntax highlighting (as in the case of this README).  And since mdsh ignores code blocks that aren't triple-backquoted, you can even "comment out" selected code blocks as documentation/usage examples by using indented blocks, tilde blocks, or more than three backquotes.

### Options, Theme, Plugins, and Dependencies

If this document were a state module, it might contain some YAML like this, to set options for the [wp-mail-smtp](https://wordpress.org/plugins/wp-mail-smtp/) plugin:

```yaml
options:
  wp_mail_smtp:
    mail:
      from_email: \(env.WP_FROM_EMAIL // _)
      from_name:  \(env.WP_FROM_NAME  // _)
      mailer: mailgun
      return_path: true
    mailgun:
      api_key: \(env.MAILGUN_API_KEY)
      domain: \(env.MAILGUN_API_DOMAIN)
```

This is already sufficient to be a valid and useful state module.  Modules are parsed using [jqmd](https://github.com/bashup/jqmd), so strings in YAML blocks can contain [jq](http://stedolan.github.io/jq/) interpolation expressions like ``\(env.MAILGUN_API_KEY)`` to get values from environment variables.  (JSON blocks can do that too, and use plain jq expressions as well as string interpolation.)

(Note: jq's `env` returns `null` for non-existent environment variables, so the above code uses the jq default operator (`//`, similar to PHP's `?:`) to replace it with an empty string so a missing variable won't put the word `"null"` in the result.  The `_` is an imposer-supplied jq function returning `""` for convenient use in YAML blocks interpolation, which cannot contain quoted strings.)

A module can include multiple YAML or JSON blocks (unindented and fenced with triple-backquotes), and their contents are recursively merged, with later values for the same key at any level overriding earlier ones (or appending to them, in the case of lists).  This merging takes place across modules, too, which means that you can (for example) define a menu in one module, and assign its location in another, while still another module adds on some extra items to the same menu.  Each module's YAML or JSON blocks need only specify the portion of the state that they want to impose themselves.

And this isn't just for options.  We can also select a theme, or indicate that a particular plugin should be activated or deactivated, or do anything else for which import tasks have been defined:

```yaml
theme: twentyseventeen
plugins:
  wp-mail-smtp:      # if a value is omitted or true, the plugin is activated
  disable_me: false  # if the value is explicitly `false`, ensure the plugin is deactivated
```

Normally, of course, it wouldn't make much sense to list a plugin in your specification just to deactivate it!  But if you had the plugin activated for a while, and then switched to a new one, you would edit it to add the `false` and leave it there until all the sites using that state file had applied it.  Also, if you have a plugin that's used only in development but should be disabled in production, you might first enable it and then load a production-specific module to disable it.  (Or vice versa.)

And speaking of loading other state modules, here's how you can do that, using a `shell` block:

```shell
# Find and load a module, a bit like php's `require_once`
require "some/state"

# Use `have_module` to test for availability
if have_module "foo/other"; then
    require "foo/other" "this/that"
fi
```

Now that we've done that, any YAML or JSON we include in our module will *override* anything set in the same parts of the specification by `some/state`, `foo/other`, or `this/that`, and any PHP code we include will be loaded after the PHP code included in those states.  (Since each module is only loaded once during an `imposer` run, we can use `require` to enforce precedence between modules.)

Notice, by the way, that state modules are loaded using *module names*, not file names!  You do not include the `.state.md` suffix, nor the full path to the file.  This is because modules are searched for along the `IMPOSER_PATH`, so that you can have globally-installed modules *and* override some of them with locally patched versions, and also so that distributed modules can refer to other modules without having to know the local directory layout for a given site.

By default, the `IMPOSER_PATH` includes your project's `imposer` directory, its plugin and theme directories, composer's local and global `vendor` directories, and wp-cli package directories.  If you `require` the state module `foo/bar`, this could actually refer to the file `imposer/foo/bar.state.md` or perhaps `~/.wp-cli/packages/vendor/foo/bar/default.state.md`, depending on where it's found first.

### Scripting The Specification

Besides shell, YAML, and JSON, the fourth language we can use to manipulate the specification is [jq](http://stedolan.github.io/jq/) -- a functional language for manipulating JSON.  Code blocks tagged `jq` are filter expressions applied to the top-level specification object.  For example, this block modifies the plugin part of the specification to activate a given plugin:

```jq
# activate `some-plugin`
.plugins["some-plugin"] = true
```

Each `jq` block must be a valid jq filter expression.  (If you want to do multiple things in the same block, separate them with a `|`. )

For most things, though, it's both clearer and simpler to just use YAML and JSON blocks, leaving jq code for those rare instances where you need to manipulate the configuration in a way not supported by YAML or JSON blocks.  But, if you need to programmatically alter parts of the specification, you can do so using shell blocks as well:

```shell
# Shell function to activate a plugin:
activate-plugin() { FILTER ".plugins[%s] = true" "$1"; }

# Activate plugins 'xyz' 'abc' and 'def'
for plugin in "xyz" "abc" "def"; do
    activate-plugin "$plugin"
done
```

Once defined by a loaded module, shell functions are then available in all other shell blocks of that module and any subsequently-loaded modules.  The `FILTER` function used here is a jqmd API function that lets you  programmatically apply a jq expression to the specification being built, with each `%s` being replaced by references to the extra arguments, in a way very similar to [$wpdb->prepare](https://developer.wordpress.org/reference/classes/wpdb/prepare/).  (Except that the placeholder(s) are always `%s` and always pass in strings.)

If you need to pass data types other than strings into jq expressions, you can insert numbers, constants, or properly quoted/escaped JSON values directly into the `FILTER` expression, or you can use jqmd's `ARGJSON` function to create a named JSON variable that can be directly referenced by jq or JSON blocks or `FILTER` expressions.  (For more on that and other things you can do with `jq` blocks and shell scripting, see the [jqmd docs](https://github.com/bashup/jqmd).)

### Processing Other Markdown Blocks

While imposer by default only understands a few types of markdown blocks, you can use jqmd and mdsh's extension features to add new types of blocks, or interpret individual blocks in a particular way.  For example, if you wanted to combine CSS blocks from your state module to configure the [Simple CSS plugin](https://wordpress.org/plugins/simple-css/), you could write markdown like this:

~~~markdown
```css +FILTER '.options.simple_css.css += %s'
/* Here's some CSS */
```
~~~

The `+`  after the language in the block tells jqmd that the remainder of the line is a shell script snippet that should be passed the block contents as an extra argument.  So the above markdown is equivalent to writing the following `shell` block:

```shell
FILTER '.options.simple_css.css += %s' $'/* Here\'s some CSS */\n'
```

This means that you can simplify the process and eliminate duplication by defining a shell function, e.g.:

~~~markdown
```shell
simple-css() { FILTER '.options.simple_css.css += %s' "$1"; }
```

```css +simple-css
/* Here's some CSS */
```

```css +simple-css
/* Here's some more */
```
~~~

Of course, these blocks don't have to be in the same file.  You could define the `simple-css` function in a state module that also activates the Simple CSS plugin, and then `require` that module from any modules that want to add `css` blocks.

That is, you could put the following in say, `simple-css.state.md`:

~~~markdown
```shell
# Activate the plugin
FILTER '.plugins["simple-css"] = true'

# Define a function other modules can use to append CSS
simple-css() { FILTER '.options.simple_css.css += %s' "$1"; }
```
~~~

And then have other state modules do this:

~~~markdown
```shell
require simple-css
```

```css +simple-css
/* Here's some CSS */
```
~~~

Notice, too, that this `simple-css` function can also be used programmatically, to e.g. insert some CSS from an environment variable or the like, by putting something like `simple-css "$SOME_VAR"` in a `shell` block.

#### Block Events

Our previous example works nicely on a small scale, but it makes *every* state module depend on the `simple-css` module in order to output CSS.  What if we could just make it so that *any* `css` block in *any* state module would automatically be sent to the simple-css plugin, or perhaps some other plugin?

This is what imposer *block events* are for.  Whenever imposer compiles a markdown file, any "miscellaneous" code blocks are converted to shell code that emits a [shell event](https://github.com/bashup/events) of the form `block of X`, where `X` is the language of the block.  So if we changed our `simple-css.state.md` file to look like this:

~~~markdown
```shell
# Activate the plugin
FILTER '.plugins["simple-css"] = true'

# Define a function other modules can use to append CSS
simple-css() { FILTER '.options.simple_css.css += %s' "$1"; }

# When a block of css is seen, pass it as an argument to simple-css
event off "block of css"                 # disable any previous handlers
event on  "block of css" @1 simple-css   # pass one arg from the event to `simple-css` function
```
~~~

...then it would no longer be necessary to include the `+simple-css` at the start of blocks.  You could simply do:

~~~markdown
```css
/* Here's some CSS */
```
~~~

In this way, at the project level you could `require simple-css` before any CSS blocks occur, and then every CSS block in *every state module* loaded after that point would be sent to the simple-css module.

And, since the CSS blocks don't specify *how* the CSS is to be included, you can change to a different CSS plugin or even use a theme setting or some other specification element: just use a different event handler at the project level.

But what if you have some theme-specific or plugin-specific CSS?  Block events include the *entire* opening line of a markdown block, which means you can do things like this:

~~~markdown
# in imposer-project.md:

```shell
require simple-css mytheme
```

```css for mytheme
/* Here's some CSS for mytheme */
```

# in mytheme.state.md:

```yaml
theme: mytheme
```

```shell
event on "block of css for mytheme" @_ event emit "block of css"
```
~~~

In this example, the `mytheme` state module registers a handler for `block of css for mytheme` that emits a `block of css` event with the same arguments as the original event.  This means that any `css for mytheme` blocks will add CSS to the project *if and only if* the `mytheme` module is included in the project!  (If `mytheme` isn't included, then the `block of css for mytheme` event will be ignored, as it has no handler.)

In this way, you can actually create state modules that include CSS customizations for multiple themes or plugins, using an appropriate convention for how you name the blocks.

Of course, you're not limited to CSS: you can do Javascript blocks, or really anything else you can imagine.  The use of events lets you loosely couple your state modules, by giving modules the ability to provide specification data in a way that isn't dependent on implementation details like which plugin(s) you're using.

For more on how shell events like these work in imposer, see the section below on [Event Hooks](#event-hooks).

### Identifying What Options To Set

In our last examples, we set an option for the Simple CSS plugin.  To do that, we had to know what Wordpress option key(s) the plugin used in the Wordpress database.  But since most users only configure Wordpress via the web UI, plugin developers rarely *document* their plugins' options at the database level.

Of course, for a sufficiently small plugin, you can find out its options by reading the source and looking for `get_option()` calls.  But what if the plugin is something huge, like an ecommerce shop or an LMS?

To help you decipher such plugins' configuration format, imposer provides tools to inspect and monitor option changes made through the Wordpress UI.  That way, you can configure a plugin via the Wordpress UI, and observe the changes it makes to the options in the database.

The main tools you will use for this process are `imposer options yaml` (which displays unimposed option values) and `imposer options review`, which lets you interactively review and approve recent option changes.

(Note: Approving a change is just a way to say, "I've seen this change and have done whatever I need to do about it, so stop showing it to me".  It doesn't save them to a state module or anything like that, although you can certainly copy and paste the relevant YAML from the changes into a state module as you review them.  You can also use `imposer options yaml` to list unapproved, unimposed changes in a more convenient format for such copying and pasting.)

To start a review, just run `imposer options review` on the development site in question.  Either you'll be immediately presented with any existing changes as YAML patch chunks (for review and approval via the `git add --patch` UI), or else the command will begin monitoring the database for new changes, waiting for you to change something via the Wordpress UI.  (You can also approve individual option changes by name, using [imposer options approve](#imposer-options-approve-name).)

Once you've approved a change (or added it to a state module and applied it), it won't show up during future runs of the `review`, `diff`, `yaml`, or `watch` subcommands of `imposer options` for that site.  This lets you filter out changes you've already mapped to a state file, and irrelevant "noise" changes, while still observing changes to option values you're still working on with `watch` or `diff`.  (You can also have your state modules blacklist constantly-changing options like `cron`, so they don't keep showing up in your diffs.)

For more details on imposer's tools for monitoring and inspecting Wordpress options, see the [imposer options](#imposer-options) command reference, below.

### PHP Blocks

In addition to shell, jq, YAML, and JSON blocks, you can also define PHP blocks.  Unlike all the other types of blocks (which are executed at the point they appear in the file), PHP blocks are "saved up" for later execution, either as part of the `imposer apply` run, or as part of a dynamically generated plugin (in the case of "tweaks").

PHP blocks are individually syntax-checked when a state file is compiled, and can contain namespaced code as long as each block is syntactically valid on its own as well as in combination with others.  (In other words,  `namespace ... {  }` wrappings can't cross block boundaries, and if a block uses such wrappings, it can't include any code outside of them.)

There are two main types of PHP blocks: extensions and tweaks.  Extensions are blocks labeled `php` , and their code is used to define tasks, steps, and resources that imposer will use to turn your JSON specification object into Wordpress database objects.  Tweaks, on the other hand, are labeled `php tweak`, and get combined into a dynamically-generated Wordpress plugin.

#### Using `use` and Namespaces

Regardless of the type of PHP block, each state module's PHP blocks are *syntactically isolated* from those of the same type in other modules.  That is, even if you don't use `namespace {...}` anywhere in your module, the contents of all the PHP blocks of a given type in your module may be combined into one or more `namespace {...}` blocks anyway, to ensure that other modules' `use` statements don't bleed into yours (or vice versa).

Note, however, that this means if you `use` something in one PHP block, then `require` other module(s) that have PHP blocks of the same type, and then have another PHP block of that type in your module, you may need to redeclare your `use` statements, because the two blocks will be in different `namespace {...}` wrappers.  To avoid this, you can move your `require` commands to the beginning or end of your module, combine your PHP blocks, or explicitly use `namespace {...}` and declare your `use` statements in each block that needs them.

#### Adding Code Tweaks

A lot of Wordpress plugins require you to add code to your theme's `functions.php` in order to get them to work the way you want.  But it can be a hassle to manage those bits of code, especially when switching themes, or when you need a tweak to be applied consistently across multiple sites.  To address this issue, state files can also include "tweaks" -- triple-backquote fenced code blocks tagged as `php tweak`, like this one:

```php tweak
add_filter('made_up_example', '__return_true');
```

When you run `imposer apply`, these code blocks are joined together and written to a dummy plugin called `imposer-tweaks` in the `$IMPOSER_PLUGINS` directory (which defaults to the `wp plugin path`).  This plugin is also activated as soon as the first tweak is processed, unless you explicitly *deactivate* the plugin at some later point, after the first `php tweak` block is reached.

Since any state file can potentially include tweaks, this is a powerful tool for modularizing and reusing these types of code snippets.

Note, however, that the **only** PHP tweaks that will be included in the generated plugin are those that are directly or indirectly included in (or `require`d by) your `imposer-project.md` or global imposer configuration.  If you manually specify state modules on the command line that contain tweaks or load other states that do, Imposer will warn you that it's not including tweaks from those modules.  (This is because the plugin is generated entirely from scratch each time you run `imposer apply`, and so those tweaks would disappear from the plugin the very next time you ran imposer, unless you specified the same modules again.)

#### Extending Imposer with PHP Blocks

Triple-backquote fenced code blocks tagged `php` are not added to a plugin: they're executed by `imposer apply` as part of a wp-cli command.  This means that you can use both the Wordpress and wp-cli APIs to impose your specification on the database.

However, just as with any other Wordpress plugin or wp-cli package, your code should generally not *do* anything directly, except define functions or classes, and register hooks.  In this case, you'll probably be registering Imposer "tasks", "resources", and "steps" more often than actions or filters, but the general principle is still the same: set up code to be called later at the correct time(s), rather than taking direct action immediately.

#### Extending WP-CLI with CLI Blocks

Blocks tagged `php` only run during `imposer apply`, but sometimes you need hooks to be run for other WP-CLI commands (such as [postmark](https://github.com/dirtsimple/postmark)).  You *could* add them as `php tweak` blocks, but then they'd be run on every web hit to your site.  So imposer provides yet another block type: `php cli`.  These blocks are saved to a separate `imposer-tweaks.cli.php` file, which will be loaded by the `imposer-tweaks` plugin if and only if a WP-CLI command is about to be run.  In this way, you can add WP-CLI-specific hooks to your state modules without adding runtime overhead to your website.

## Extending Imposer

### Defining Tasks and Steps

An imposer **task** is a named collection of pausable steps (callbacks) that are invoked with data from the completed specification object assembled by the state modules.  For example, if we wanted to create a task that processed a top-level specification value like this:

```yaml
hello: world
```

We could add the following PHP code block to a state file:

```php
Imposer::task("Hello World")
    -> reads('hello')
    -> steps(function ($msg) {
        WP_CLI::line("Hello $msg");
    });
```

Tasks only run if at least one of the values they read are present in the specification.  So if this PHP block were included in a state module by itself, it would *not* output a message, unless another module defined a `hello` property at the top level of the specification.  This task will also only run once, no matter how many times the top-level `hello` property is specified or re-specified (because only the last such specification will be in the final specification object).

Let's try a slightly more sophisticated version, that processes a specification like this:

```yaml
hello-world:
  greeting: Hello
  recipient: World
```

```php
Imposer::task("Parameterized Greetings")
    -> reads(['hello-world','greeting'], ['hello-world','recipient'])
    -> steps(function ($what, $who) { WP_CLI::line("$what, $who!"); });
```

As you can see, you can pass multiple arguments to `reads()`, and they will all be forwarded to your step functions, as long as at least *one* of the keys exists.  (Or else the task won't be run.)  Also, if an argument name is an array, then it is a path of property names to be traversed from the root of the specification object.

Thus, the step function here receives the `greeting` and `recipient` sub-keys of `hello-world` as its `$what` and `$who` parameters.  If only one of the keys is present, the task will still run, but the other parameter passed to each step will be `null`.

A task can read any number of specification properties.  If none are specified, the steps will be called with no arguments, and the task will always run.  Otherwise, the task will only run if at least one of the named properties is present in the specification at runtime.

Both the `reads()` and `steps()` methods can be called multiple times, in which case they add more parameters or more steps.  The added parameters are passed to every step, and added steps receive all parameters defined at the time they run.  Steps are started in the order they are added (and can be added at any time, even by other steps), but steps can also be *paused*, allowing other steps and tasks to be run.

(This pausing happens whenever another task or step is responsible for importing something that the pausing step needs to reference, such as a menu item referring to a post that hasn't been imported yet.)

#### Task Dependencies

Tasks normally run in the order they were defined, and run all their steps until there's nothing left to do.  But the Wordpress database has a complex schema of things pointing to other things all over the place, and no matter what order you run the tasks in, there's always the possibility of needing to refer to something that hasn't been created yet.

For this reason, imposer also has the concept of *resources*: things that are created or referenced by tasks.  Let's say we are writing a task to set widget restrictions in the database for the "Restict Widgets" plugin to act on, and need to look up page IDs from information in the specification.  Normally you might write something like:

```php
if ( ! $page_id = url_to_postid($url) ) {
    WP_CLI::error("No post/page found for path '$url'");
}
```

But if you did this in an Imposer task, how would you know whether the user incorrectly specified the path, or whether the page just hadn't been imported yet?

For that purpose, Imposer provides *references*:

```php
$page_id = yield Imposer::ref('@wp-post', $url);
```

Task step functions can `yield` references to obtain database IDs.  In the example above, we ask for a WordPress post (`@wp-post`) resource.  If the post already exists, the ID is returned immediately.  But if it doesn't exist, the function's execution is suspended until the needed post is created, or until all other non-suspended tasks are complete...  at which point the function resumes with a thrown exception for the invalid URL.

(Note: when a step is suspended, the next step in the same task is started, followed by any other tasks that still have unstarted steps.  The step will be resumed as soon as Imposer can determine that the requested resource is available.)

#### References and Lookups

The first argument passed to `Imposer::ref()` is the name of a *resource type*.  (By convention, resource type names begin with `@`, and this may be enforced in future.)  The second argument is a "key", and the optional third argument is a "key type".

Imposer pre-defines a few resource types: `@wp-post`, `@wp-user`, and a `@wp-*-term` type for each taxonomy.  (For example, `@wp-category-term` for categories, `@wp-post_tag-term` for tags, and so on.)

Each resource type can define *lookups*: functions that turn a key of a particular type into a database id, or `null` if the item doesn't currently exist.  In the case of the `@wp-post` resource type, there are two key types defined, each with their own registered lookups: `path` and `guid`.  The `url_to_postid()` function is used to look up items by path.

Each resource type also has a *default* lookup, the one that's used when only two arguments are given to `Imposer::ref()`.  Usually, this lookup will try the other lookups in turn.  (For example the `@wp-post` default lookup first tries a `guid` lookup, and then a `path` lookup, while `@wp-user` tries an `email` lookup followed by a `login` lookup, and the `@wp-*-term` default lookup tries `slug` and then `name`.)

You can register additional lookup functions using `Imposer::resource($typename)` to fetch (or create) a resource type object, then calling its `addLookup($handler, $keyType='')` method.  For example, if you wanted to be able to look up posts by title, you could do something like this:

```php
Imposer::resource('@wp-post')->addLookup('some_function', 'title');
```

...as long as `some_function` is a function taking a title and returning a post ID, or `null` if a post is not found.

Lookup functions also receive two additional arguments: the key type (`"title"` in this case) and the resource object (`Imposer::resource('@wp-post')`).  Now that the lookup exists, we can do things like this in our task steps:

```php
$post_id = yield Imposer::ref('@wp-post', "some title", 'title');
```

You aren't restricted to the built-in resource types, either.  For example, if you have a plugin called `myforms`, you might define lookups for a `@myforms-form` resource type to allow looking up forms in the database by various keys.  The basic idea is that you can look up any possible item by a key, and automatically pause execution to let other steps run if needed.

#### Avoiding Dependency Loops and Deadlocks

Most inter-task dependencies are between different types of thing, that don't refer back to one another.  For example, menu items can refer to taxonomy terms or posts, but posts don't usually refer to menu items.  In some situations, however, such as courses referring to their lessons and vice versa, you may have to break up tasks into smaller pieces to avoid a deadlock where the course-import task needs a lesson to be loaded, but the lesson-import task is waiting for a course.

In such a scenario, imposer will break the deadlock by picking an arbitrary lookup to fail.  But since that's not very helpful, it's usually better to create individual steps for each item to be created or updated, and separate to do any linking that might be circular (such as bidirectional links between a course and its lessons, or next/previous links between posts).  That way, the items themselves can still be created, even if the linking steps are blocked waiting on the items to be created.

You don't have to know all the steps in advance, though, since you can call `Imposer::steps(function(){ ... });` at any point within another step, to add a new step function to the current task, that will be run later.  So, you can initialize a task with a step that creates steps to import each item, and each item step can create steps to set up inter-item links.  That way, if any step has to pause, the other steps can still keep going.

### Distributing Modules and Extensions

After you've defined and tested your task definitions, you have a variety of ways you can distribute them to other imposer users.  The simplest way is just to include the module in your plugin, theme, or wp-cli package.  If you name it `default.state.md` (either in the root of your extension, or in an `imposer-states/` subdirectory), then users who have it installed can simply `require "theme-or-plugin-name"` or `require "yourorg/yourpackage"` in a shell block, to make use of it in their project.  (Or they can run `imposer apply theme-or-plugin` or `imposer apply yourorg/yourpackage`.

However, if your tasks are complex, you may wish to put the bulk of their code in PHP files instead of embedding them in a `.state.md` file.  You can expose API functions or classes from your extension, and only register tasks in the state file to pass the needed specification values to the API.  (One benefit of this approach is that it exposes a nice JSON-accepting API for whatever your extension does.)

You, may, however, wish to offer your users an option to use your tasks *without* needing to require or apply a state module, apart from whatever settings they've included in their own.  If so, you can do this by register a callback for the `imposer_tasks` action, and create your tasks from that callback.

You can of course also have state modules besides a `default.state.md`: you can use them to provide a variety of profiles for configuring your plugin or theme.  For example, if your theme has various example or starter sites, you can define them as state files, and people could import them with `imposer apply my-theme/portfolio`, to load the `portfolio.state.md` in the root or `imposer-states/` subdirectory of your theme.  Such modules can depend on other modules in your theme or other projects, and users can build their own modules on top of those to extend your starters to create their own sites.

# Reference

## Installation, Requirements, and Use

Imposer is packaged with composer, and is intended to be installed as a wp-cli or composer package, i.e. via `wp package install dirtsimple/imposer`, `composer require dirtsimple/imposer:dev-master` or `composer global require dirtsimple/imposer:dev-master`.  No matter how you install it, though, be sure that the appropriate `vendor/bin` directory is on your `PATH`, so that you can just run `imposer` without having to specify its exact location every time.  (For example if you use `wp package install`, you'll likely need `~/.wp-cli/packages/vendor/bin` on your `PATH`.)

In addition to PHP 5.6+, Composer, and Wordpress, imposer requires:

* [jq](http://stedolan.github.io/jq/) 1.5 or better
* the bash shell, version 3.2 or better

Imposer is not yet regularly tested on anything other than Linux, but it *should* work on OS/X and other Unix-like operating systems with a suitable version of bash and jq.  (It *can* be run on Windows using the Cygwin versions of PHP, jq, git, and bash but will run *painfully* slowly compared to the Linux Subsystem for Windows or using a Linux VM or Docker container.)

To use Imposer, you must have at least *one* of the following files in the root directory of your project:

*  `imposer-project.md`,
* `composer.json`, or
*  `wp-cli.yml`.

Imposer will search the current directory and its parent directories until it finds one of the three files, and all relative paths (e.g. those in `IMPOSER_PATH`) will be interpreted relative to that directory.  (And all code in state module files is executed with that directory as the current directory.)  If you have an `imposer-project.md`, it will be loaded as though it were the state file for a module called `imposer:project`.

In addition to the one or more of the above three files, you can also have an `.imposer-env` file containing environment variables in [docker-compose .env file format](https://docs.docker.com/compose/compose-file/#env_file) (i.e. raw values without quoting or escape sequences).  This allows you to store keys, credentials, hostnames, and other configuration in a separate file that can be excluded from revision control.  Any variables found in the file are added to the OS environment while imposer is running, and for any commands imposer executes.

Basic usage is `imposer apply` *[modulename...]*, where each argument designates a state module to be loaded.  Modules are loaded in the order specified, unless an earlier one `require`s a later one, forcing it to be loaded earlier than its position in the list.  (You don't have to list any modules if everything you want to apply as a specification is already in your `imposer-project.md`, or in modules `require`d by it.)

## Lookup and Processing Order

### State Module to Filename Mapping

For convenience, module names do not include the `.state.md` suffix, and can also just be the name of a composer package (e.g. `foo/bar`) or theme/plugin directory (e.g. `sometheme` or `someplugin`).  Given a string such as `foo/bar/baz`, imposer will look for the following files in the following order, in each directory on `IMPOSER_PATH`, with the first found file being used:

* `foo/bar/baz.state.md` (the exact name, as a `.state.md` file)
* `foo/bar/baz/default.state.md` (the exact name as a directory, containing a `default.state.md` file)
* `foo/bar/baz/imposer-states/default.state.md` (the exact name as a directory, containing an `imposer-states/default.state.md` file)
* `foo/bar/imposer-states/baz.state.md` (the namespace of the name as a directory, containing the last name part in an `imposer-states` subdirectory)

(The last rule means that you can create a composer package called e.g. `mycompany/imposer-states` containing a library of state modules, and then require e.g. `mycompany/foo` to load `foo.state.md` from the root of the package (i.e. `vendor/mycompany/imposer-states`).  Or you can make a Wordpress plugin called `myplugin`, and then require  `myplugin/bar` to load `bar.state.md` from the plugin's `imposer-states/` directory or its root.)

### IMPOSER_PATH

The default `IMPOSER_PATH` is assembled from:

* `./imposer` (i.e., the `imposer` subdirectory of the project root)
* `$IMPOSER_THEMES`, defaulting to the Wordpress themes directory as provided by `wp theme path` (e.g. `wp-content/themes/`)
* `$IMPOSER_PLUGINS`, defaulting to the Wordpress plugin directory as provided by `wp plugin path` (e.g. `wp-content/plugins/`)
* `$IMPOSER_VENDOR`, defaulting to the `COMPOSER_VENDOR_DIR` (e.g. `vendor/`), if a `composer.json` is present
* `$IMPOSER_PACKAGES`, defaulting to the `vendor/` subdirectory of the `wp package path` (typically `~/.wp-cli/packages/vendor`)
* `$IMPOSER_GLOBALS`, defaulting to the global composer `vendor` directory, e.g. `${COMPOSER_HOME}/vendor`

(You can remove any of the above directories from consideration for the default `IMPOSER_PATH` by setting the corresponding variable to an empty string.)

This allows states to be distributed and installed in a variety of ways, while still being overrideable at the project level (via the main `imposer/`) directory.  (For example, if you add an `imposer/foo/bar.state.md` file to your project, it will be loaded in place of the `bar` state of any theme/plugin named `foo`, or the default state of a composer package named `foo/bar`.)

### Processing Phases for `imposer apply`

While we're discussing precedence order, you may find it useful to have an explicit listing of the phases in which `imposer apply` executes:

* First phase: **load and execute state modules** by converting them to (timestamp-cached) shell scripts that are then `source`d by imposer.  (The `all_modules_loaded` event fires at the end of this phase.)  During this phase, shell blocks are executed, and most non-shell code blocks are accumulated in memory for use by later phases.  YAML and JSON blocks are converted to `FILTER` commands that generate a jq filter pipeline.
* Second phase: **generate a JSON specification object** by running the `jq` command on the jq filter pipeline code generated during the previous phase.
* Third phase: **apply changes to Wordpress** by:
  1. Firing the `before_apply` [shell event](#event-hooks) (which will also generate the `imposer-tweaks` plugin file for any accumulated [code tweaks](#adding-code-tweaks))
  2. Running imposer's PHP code (via `wp eval`) to load the PHP code accumulated during the first phase, then running the imposer [actions and filters](#actions-and-filters) and tasks to actually update the Wordpress database.  (This step may run multiple times if `Imposer::request_restart()` is called, e.g. when the theme is changed or plugins are activated or deactivated.)
  3. If the previous steps finished without a fatal error, the `after_apply` shell event is then run.

So, even though it looks like shell, PHP, and YAML/JSON/jq code execution are interleaved, in reality all the shell code is executed first: it's just that any code block *other* than shell code blocks are translated to shell code that accumulates either jq or PHP code for execution in the second or third phase.  This means that you can't (for example) have two YAML blocks reference the same environment variable and change its value "in between them" using shell code, because whatever value the environment variable has at the end of phase one is what will be used when *both* blocks are executed during phase two.

### State and Task Execution Order

State module files are processed in *dependency order*, meaning that any module can trigger the processing of other modules, using `require`.  `require` does nothing if the requested state has already been processed, *or is being processed*.  (That is, in the event of a circular dependency, the state that completes the cycle will proceed even though its dependency is not actually finished yet.  Future versions of imposer may issue warnings or abort in this circumstance.)

Tasks work a bit differently.  Normally, tasks are executed in *definition order* (which of course depends on the execution order of the state modules providing the definition), and steps are executed in the order they are added to a specific task.  (This means that if, after adding ten tasks with no steps, and then add a step to the first task, that step will be the first thing that actually executes.)

However, steps can also *pause*.  Meaning, if a task needs to refer to a Wordpress object that doesn't exist yet, and it calls e.g. `yield Imposer::ref('@wp-post', $xyz)`, and the post doesn't exist yet, then the step will be suspended and the next step in the task (or the first step of the next task) will run, until *all other unpaused steps* have had a chance to proceed, or until the target post is created.

Notice that this means that if you wish two things to be done in a particular order, they *must* be part of the same step, or else the preceding steps must not `yield` anything.  If no step pauses, then all steps will be fully executed in their original definition order.  But paused steps can resume at any time, and any other step or task may have run while they were `yield`ing.

The reasoning behind this approach is that unless you are imposing state on an empty database, *most* of the resources your states need to reference will already exist on most imposer runs, so it's easier to ask forgiveness (via possible `yield` suspension) than permission (i.e. predefining inter-task dependencies).  Dependencies between tasks could overconstrain the system or lead to unintended dependency loops, but dynamic suspension can generally sort itself out.

## Command-Line Interface

Note: imposer always operates on the nearest directory at or above the current directory that contains either an `imposer-project.md `, a `wp-cli.yml`, and/or a `composer.json`.  This directory is assumed to be your project root, and all relative paths are based there.

(Note also that imposer does not currently support operating on remote sites: state files are always read and run on the *local* machine, and cannot be executed remotely by wp-cli.  If you need to run a command remotely, use something like e.g. `ssh myserver bash -c 'cd /my/wp-instance; imposer apply'` instead.)

### imposer apply *\[module...\] \[wp-cli option...\]*

Load and execute the specified state modules, building a JSON configuration and accumulating PHP code, before handing them both off to `wp eval` to run the PHP code, invoke the imposer [actions and filters](#actions-and-filters), and execute the defined tasks, firing the shell-level [event hooks ](#event-hooks) along the way.  Output is whatever the tasks output using the WP_CLI interface.

The first argument that begins with `--` is assumed to be a wp-cli global option, and all arguments from that point on are passed through to wp-cli without further interpretation.  (Potentially useful options include `--[no-]color`, `--quiet`, `--debug[=<group>]`, `--user=<id|login|email>`, and `--require=<path>`.)

### imposer options

The `imposer options` subcommand provides various sub-subcommands for inspecting the contents of, and monitoring changes made to, the Wordpress options table.

For the most part, you will only use these subcommands on a development instance of Wordpress, in order to discover and verify what options in the database match what parts of a plugin's configuration UI.  (So you can figure out what to put in your state files for production, or verify that your state files are setting things correctly.)

By default, changes to options are monitored using a git repository in `$IMPOSER_CACHE/.options-snapshot`, but this location can be overridden by setting `IMPOSER_OPTIONS_SNAPSHOT` (e.g. via the [imposer env](#imposer-env) command), or by passing the `--dir` option to `imposer options`.  (For example, `imposer options --dir foo review` runs the `review` command against a git repository called `foo` under the current project root.)

Note that although snapshot directories are managed using git, their contents should *not* be considered part of your project, and should not be committed or pushed to any remote servers, as they may contain security-sensitive data.  (Note, too, that you can safely *delete* (or `imposer options reset`) a snapshot directory at any time, as nothing is lost except the knowledge of what options were changed since the last fully-approved `review`.)

Most `imposer options` subcommands provide paged and colorized output, unless their output is piped or redirected to a file.  JSON is colorized using `jq`, diffs are colorized with `colordiff` or `pygments` if available, and paging is done with `less -FRX`.  (You can override the diff coloring command by setting `IMPOSER_COLORDIFF`, the yaml coloring command with `IMPOSER_YAML_COLOR`, and the paging command via `IMPOSER_PAGER`.  Setting them to empty disables the relevant coloring and/or paging functions.)

Because many plugins and themes store frequently-changing state information in their options (such as timestamps, counters, undo logs, etc.), this may produce "noise" in your option lists, diffs, and reviews.  In order to filter these options out, you can add `exclude-options` and `filter-options` calls in your state modules, to exclude "noise" options for the relevant plugins, or to avoid recording security-sensitive data in the snapshot directory.  (For more information see the section below on [Option Filtering](#option-filtering).)

#### imposer options review

Interactively revew and approve changes made to the YAML form of all non-transient Wordpress options since the last review, the last time the snapshot directory was erased, or an option was imposed to its current value.  (If there are no changes pending, the command waits, taking snapshots of the options in the database every 10 seconds until a change is detected.)

The review process has the same UI as `git add --patch`: any changes you approve will not show up on future reviews, allowing you to figuratively "sign off" on the changes that you understand, or which are irrelevant to your current goals.  Once you've approved a change, it will not show up during future runs of `imposer options` subcommands such as `review`, `diff`, or `watch`.

(Note: on Alpine linux, the default `git` package doesn't include the UI for `git add --patch`.  So, if you're working in an Alpine environment (e.g. in a docker container), you'll need to install the Alpine `git-perl` package to make `options review` work correctly.)

#### imposer options approve *[name...]*

Approve changes to the named options, without needing to go through the interactive review.  Names must not contain any characters other than letters, numbers, or `_`, or else they need to be double-quoted inside single quotes, e.g. `'"some-option"'` in order to be handled correctly.  (This is because the names are actually interpreted as jq path expressions, after adding a leading `.`)

Whether the named options were added, updated, or deleted, the changes are immediately approved, and will not show up in the `diff` , `review`, or `yaml` subcommands of `imposer options`, until/unless those options are changed again.

#### imposer options reset

Reset the current snapshot, implicitly approving all current changes.  This command is equivalent to running a review and approving all currently-pending changes.

#### imposer options list *[list-options...]*

This command outputs a JSON map of all non-excluded, non-transient wordpress options, in the form they would need to appear under the `options` key in the imposer state.  (You can use the `wp option list` options `--search=`, `--exclude=`, and `--autoload=` to limit the output to a desired subset of options.)

#### imposer options jq [jq-options...]

This command pipes a JSON map of all non-excluded, non-transient Wordpress options to jq, with the *jq-options* passed along to jq.  The output is colorized and paged if sent to a TTY.

#### imposer options diff

Compare the current output of `imposer options list` against the last approved snapshot, displaying the differences as a unified diff (possibly colorized and paged) in YAML format.

#### imposer options yaml

Compare the current output of `imposer options list` against the last approved snapshot and the current project specification, outputting the minimal YAML that would be required to update or insert any new option values that are not yet approved and have not yet been specified in the project's current state, or which need to be changed in the specification.  If sent to a TTY, output is paged and colorized (if pygments is installed).

Note: although this command is intended to make it easier to "export" option changes to your imposer state modules, do note that you should not copy everything blindly to a state module: make sure you 1) understand what the actual options do, 2) use the review command and filtering facilities to keep the YAML as brief as possible, and 3) be selective in your copy-and-pastes.  (For example, it's rarely a good idea to impose options that look like `someplugin_version` or `someplugin_installed_date`, because plugins might use these values to drive database updates or to regenerate cache files.)

(A good portion of imposer's value comes from the ability to group specifications in logical features, rather than simply being an import/export facility.  So if you're copying options wholesale without grouping options into relevant features and documenting why those particular values are desirable, you're just moving your maintenance headaches around, rather than actually solving them.)

#### imposer options watch

Every 10 seconds, clear the screen and display the first screenful of output from `imposer options diff`.  (Use Control-C to exit.)

### imposer env

The `imposer env` command lets you read and write environment variables stored in the `.imposer-env` file in your project directory.  The file is in docker-compose format, meaning that leading and trailing spaces are ignored, quotes are treated as literal characters, and no escape sequences are recognized.  Variables set in the file are exported to the operating system environment while imposer runs, so they can be used for interpolation in YAML or shell blocks, or read by PHP code for the PHP part of an `imposer apply` run.  They do not, however, affect the WordPress runtime environment (outside of wp-cli code run by imposer).

#### imposer env set [+]*VAR*[=*value*]...

Set or unset one or more variables.  If a variable name is preceded by a `+`, the variable is only set if it is not already set.  (In other words, `+` means "add this value as a default if there's no definition yet".)  If the `=` and value is omitted, the variable is unset and deleted from the file, allowing the operating system environment's value for that variable (if any) to show through.

#### imposer env get *VAR*

Write the value of the specified variable to standard output, if defined in the file.  (Otherwise, there is no output.)

#### imposer env parse *[VAR...]*

Extract one or more variable definitions from the file, outputting them as `VAR=value` lines without any escaping.  The order of the output is based on the order in the file, not the order of names given, and skips variables not found in the file.  If no names are given, all variables in the file are output.

#### imposer env export *[VAR...]*

Write out the specified variables as `export VAR=value` lines (if they are found in the file), with values escaped in a manner suitable for safe execution in the shell.  The order of the output is based on the order in the file, not the order of names given, and skips variables not found in the file.  If no names are given, all variables in the file are output.

### Diagnostic Commands

Note: since these diagnostic commands do not actually invoke wp-cli, any `--`-prefixed options will be ignored.  As with `imposer apply`, however, the first argument beginning with `--` still terminates the list of modules.  (This is so that you can easily edit a complex command line to change from `apply` to `json` or `php`, etc. with the same arguments.)

#### imposer json *[module...]*

Just like `imposer apply`, except that instead of actually applying the changes, the JSON specification is written to standard output for debugging.  The `all_modules_loaded` shell event will fire, but the `before_apply` and `after_apply` events will not.  (Any jq and shell code in the states will still execute, since that's how the JSON is created in the first place.)

If the output is a tty and `less` is available, the output is colorized and paged.  `IMPOSER_PAGER` can be set to override the default of `less -FRX`; an empty string disables paging.

#### imposer php *[module...]*

Just like `imposer apply`, except that instead of actually applying the changes, the accumulated PHP code is dumped to stdout instead.  The `all_modules_loaded` shell event will fire, but the `before_apply` and `after_apply` events will not.

If the output is a tty and `pygmentize` and `less` are available, the output is colorized and paged.  `IMPOSER_PAGER` can be set to override the default of `less -FRX`, and `IMPOSER_PHP_COLOR` can be set to override the default of `pygmentize -f 256 -O style=igor -l php`; setting them to empty strings disables them.

#### imposer sources *[module...]*

Like the `json` and `php` commands, except that a list of all source state files is output, one file per line, using paths relative to the project root. (This can be useful as input to a file watcher like [entr](http://entrproject.org/), to watch the files for changes and re-run `imposer apply`.)

If the output is a tty and `$IMPOSER_PAGER` is available (`less -FRX` by default), the output is paged.

The output includes all source files read (or cached), including any global config files and the `imposer-project.md`, if any.  If a state file reads non-state files as part of its operation, it should call the shell function `mark-read` with one or more file names.  The named files will then be output when this command is run.

#### imposer tweaks

Outputs the PHP that would be written to `imposer-tweaks.php` if `imposer apply` were run.  The output is colorized and paged (if possible) according to the same rules as for [`imposer php`](#imposer-php-state).

#### imposer tweaks cli

Outputs the PHP that would be written to `imposer-tweaks.cli.php` if `imposer apply` were run.  The output is colorized and paged (if possible) according to the same rules as for [`imposer php`](#imposer-php-state).

#### imposer path

Output a `:`-separated list of absolute paths that imposer will currently look for states under.  The output takes into account the current value of  `IMPOSER_PATH`, if any.  This value is useful for checking that imposer is searching where you think it's searching.

#### imposer default-path

Like `imposer path`, except that the current value of `IMPOSER_PATH` is ignored.  This is useful for computing what value you might want to put into `IMPOSER_PATH` to speed up start times.

## Specification Schema

### Theme, Plugins, and Options

#### Theme Switching

The `theme` property of the specification object specifies the slug of the theme to use for the site.  If it is different from the current theme in the database, imposer switches the theme and then restarts the current PHP process and all tasks, so that the correct functions, filters, caches, etc. are in memory for all remaining tasks.

```yaml
theme: some-theme
```

#### Plugin Activation

The `plugins` property of the specification object is an object mapping plugin names to their desired activation state.  A JSON  `true` or `null` means "activate"; `false` means "deactivate".  If any plugins have a different activation state in the database than in the specification, imposer activates or deactivates the relevant plugins and then restarts the current PHP process and all tasks, so that the correct functions, filters, caches, etc. are in memory for all remaining tasks.

```yaml
plugins:
   # Plugins with null or boolean `true` values will be activated
   this-plugin-will-be-activated: true
   so-will-this-one: yes
   me-too:

   # Plugins with boolean false value will be deactivated
   another-plugin: false
```

#### Option Patching

The `options` property of the specification is an object mapping Wordpress option names to their desired contents.  If an option is an object in the specification, and a PHP array or object in the database, the option value in the database is recursively merged, so that the specification need not include *all* of a complex option value's contents.

```yaml
options:
  blogname: My Blog
  blogdescription: Just another (Imposer-powered) WordPress site
  timezone_string: "\\(env.TZ)"
  gmt_offset: ""
  start_of_week: 0
```

The merge algorithm used maps JSON object properties to PHP array keys or object properties within existing options.  Option values or sub-values that are not JSON objects are not merged, however: they are overwritten.  This means that if an option value or sub-value is an array in the specification, it *must* be specified fully in order to produce the correct option value in the database.

### Menus and Locations

Menus are located under the top-level specification property `menus`, as a map from menu names, to menu objects or menu item arrays.  If a menu is an object, it can have `description` , `location`, and `items` properties.  If a menu is an array, it is treated as if it were an object with only an `items` property.

Menus are synced as whole objects: any existing menu items in the database for a menu that are *not* listed in the specification will be deleted.  Similarly, if you specify *any* locations for a menu, the menu will be removed from any menu slots that are not listed in the specification (for the corresponding theme).

```yaml
menus:
  "Simple Menu, no Location":  # A menu w/five items: about, contact, posts, recipes, twitter
    - page: /about
    - page: /contact
    - archive: post
    - category: Recipes
    - url: https://twitter.com/pjeby  # custom link with title and _target
      title: Twitter
      target: _blank

  Menu with Properties:
    description: "Nothing really uses this, it's just an example"
    items:
      - page: /shop     # page with sub-items
        items:
          - page: /cart
          - page: /checkout
          - page: /my-account
      - page: /terms-and-conditions
        classes: popup
```

#### Menu Item Destinations

Each menu item is an object that has at most one of the following property sets, defining the item's type and destination:

* "Custom" menu items have a `url` property and a `title`.  The resulting menu item will simply be a link to the given URL.
* "Page/Post" menu items have a `page` property with the URL of the desired page.  The resulting menu item will take its default title from the named page or post.  The page or post is looked up using `url_to_postid()`, and must exist.  If it does not, menu importing blocks until all tasks producing `@wp-posts` resources have complete.)
* "Archive" menu items have an `archive` property that's a string naming a custom post type (using its Wordpress internal type name).  The resulting menu item will be a link to the archives for that post type.
* "Term" menu items can be created in one of three ways:
  * An item with a `term: {some_taxonomy: term_slug}` property will link to the term `term_slug` in `some_taxonomy`
  * An item with a `tag: tagname` property will link to the named tag in the `post_tag` taxonomy
  * An item with a `category: categoryname` property will link to the named category.

#### Menu Item Data

Menu items can also have any or all of the following optional properties:

* `items` -- An array of menu item objects, which will become children of the current item
* `title` -- The title to be displayed for the menu item.  For a "Custom" menu item, this must be non-empty or else the menu item will be virtually invisible.  (For all other menu item types, Wordpress will generate a default title based on the destination.)
* `attr_title` -- the value of the `title` attribute for the menu link (what browsers will usually show as a tooltip on hover)
* `description` -- a more detailed description; some themes may display this somewhere for certain menu locations
* `classes` -- a space-separated list of HTML classes to add to the menu item.
* `xfn` -- the XFN link relationship for the link destination
* `target` -- the `target` HTML attribute for the link, e.g. use `_blank` to make the menu item open in a new window.
* `id` -- an identifier for sync purposes, that should be unique within the specific menu.  If none is given, a default one is generated based on the menu item's link destination.  (The `id` is only used to minimize the need to create and delete menu items when menu items are moved around.)


#### Menu Locations

A menu's `location` property, if present, will be used to assign the menu to one or more locations on your site.  If the location is a string, the menu will be assigned to the location of that name in the current theme, and removed from all other locations.  If the location is an object, its property names are treated as theme names, and the values can be strings (naming a specific location in the named theme) or arrays (naming multiple locations in that theme).   If the location is an array, each element can be a string, object, or array in the same manner.

```yaml
menus:
  Simple Location, Current Theme:
    location: footer-menu   # Just one location, in whatever the current theme is

  Simple Location, Specific Theme:
    location: { someTheme: primary-menu }   # one location, specific theme

  Lots of Locations:
    location:
      - primary-menu              # location in the currently-active theme
      - aTheme: secondary-menu    # another location, in a specific theme
      - otherTheme:               # multiple locations in a third theme
        - slot-a
        - footer-right
```

When a menu specifies a location for a specific theme (whether explicitly named or not), it is also **removed** from all other locations in that theme, to prevent duplication when you move a menu to a new location.  (Remember: multiple modules or YAML/JSON blocks can contribute properties to the same specification object, so you can always define a menu in one module, and add its location from another.)

### Taxonomy Terms

You can create taxonomy terms (eg. categories, tags, etc.) using the `terms` top-level specification property, e.g.:

```yaml
terms:
  post_tag:
    - Some Tag
    - Another Tag
  category:
    Things:
      term_meta: { tax_position: 1 }  # sort-order used by simple-taxonomy-ordering plugin
      children:
        - Stuff
    Other:
      term_meta: { tax_position: 2 }
```

#### Term Sets, Objects, and Parents

The `terms` property is an object mapping Wordpress internal taxonomy slugs (e.g. `post_tag` for tags, `category` for categories, etc.) to *term set*.  A term set is an array or object of terms, which themselves can be a string or an object.  A string that's a term is equivalent to an object with a `name` property, so this:

```yaml
terms:
  post_tag:
    - Some Tag
    - Another Tag
```

is exactly equivalent to:

```yaml
terms:
  post_tag:
    - name: Some Tag
    - name: Another Tag
```

Term objects accept the  same properties as the `$args` for [`wp_insert_term`](https://developer.wordpress.org/reference/functions/wp_insert_term/), plus a `term_meta` property for setting or deleting term metadata, and a `children` term set whose terms will default to having the enclosing term as their `parent`.  (You can explicitly set `parent` to `0` to make a term a root term, and you can use a term slug as a parent instead of an ID.)  Not all taxonomies allow parents, so don't use `parent` or `children` unless the taxonomy is hierarchical.

The `term_meta` property of a term object can be used to set or delete term metadata: any keys with a value of `null` will have that metadata item deleted, while the others will be set to the non-null value given.

#### Term Names and Slugs

When a term set is an object (as in the `category` example earlier), the object property names are taken as either slugs or names, depending on what the terms in the set need.  For example, this:

```yaml
terms:
  category:
    foo: Foo
    Bar: { slug: bar }
    baz:
      name: This
      slug: that
```

is equivalent to:

```yaml
terms:
  category:
    - name: Foo
      slug: foo
    - name: Bar
      slug: bar
    - name: This
      slug: that
```

In other words, if the term lacks a name, then the enclosing property name is used as the name, otherwise the enclosing property is used as a slug.

All of these considerations apply to both top-level term sets and those specified as `children:` of another term.

**Note**: While slugs are optional, there are certain scenarios where they're required in order to avoid undesirable effects.  Terms without slugs are looked up by name in the Wordpress database, so if you rename a term in your specification and it doesn't have a slug, a *new* term will be created, leaving the old one in place.  Likewise, it will not be possible to create or update multiple terms with the same name (as Wordpress allows in hierarchical taxonomies), unless each has a distinct slug.  If you don't have either of these scenarios, however, you can just use names.

#### Term Hooks

While imposer has a relatively small vocabulary of supported properties per term, you can extend or alter it using the `imposer_term` or `imposer_term_$taxonomy` action.  Hooks registered for these actions receive an array-like object with extra properties and methods, such as:

* `set_meta($path, $value)` -- set term metadata at `$path` to `$value`; `$path` is either a metadata name or an array specifying a path to traverse into the (possibly-existing) metadata before setting the value, much like the `wp term meta patch insert` command.
* `delete_meta($path)` -- like `set_meta()` except doing the equivalent of `wp term meta patch delete` instead.

Data on the object can be accessed either in array-like (e.g. `$data['slug']`) or object-like (e.g. `$data->slug`) fashion.  (This does not apply to the *contents* of the object, which are scalar values or arrays.

Hook functions for these actions can receive a second argument, which is the array offset at which the term appears in the taxonomy or its parent (or null if the term was in an object instead of an array).

For example, the following "PHP tweak" works with the [simple-taxonomy-ordering plugin](https://wordpress.org/plugins/simple-taxonomy-ordering/) to force categories to be displayed in the same order they're given in the imposer specification:

```php tweak
add_action('imposer_term_category', function($term, $offset) {
    if ( isset($offset) ) $term->set_meta('tax_position', $offset);
    else $term->delete_meta('tax_position');
}, 10, 2);
```

The above code will either add a `tax_position` meta field to each category (if it's found in an array), or remove the property (if it's found in an object).

### Widgets and Sidebars

Wordpress internally identifies widgets by an ID composed of a widget type and a number.  But this isn't very composition-friendly, since different state modules might unintentionally pick the same number.  To solve this issue, imposer lets you give widgets symbolic *names*, which can be whatever you want and have no inherent relationship with Wordpress's widget ids.  For example, this YAML block defines two named widgets and places them in two sidebars:

```yaml
widgets:
  llms_syllabus:
    title: "Contents"
    widget_type: course_syllabus

  llms_progress:
    title: "Your Progress"
    widget_type: course_progress

sidebars:
  llms_course_widgets_side:
    - llms_progress
    - llms_syllabus

  llms_lesson_widgets_side:
    - llms_progress
    - llms_syllabus
```

Sidebars are defined under the top-level specification property `sidebars`, as a map from sidebar IDs to lists of widget names.

Any widget names used in a sidebar must be defined under the top-level specification property `widgets`, as a map from widget names (which can be any string), to the properties the widget should have.  The `widget_type` property is required, and it names the internal "id base" of the corresponding widget object.  For example, the built-in  Calendar widget has a widget type of `calendar`, archive widgets are `archives`, and so on.

Sometimes, widget properties need to reference Wordpress database objects such as posts, menus, terms, etc.  But since database IDs aren't portable, you may need to use other identifiers in the specification, and then look up the relevant object IDs using those other identifiers (e.g. a post GUID, a menu name, etc.)

To support doing these kinds of transformations, widget properties are passed through two filters before they're actually stored in the database:

* `apply_filters("imposer_widget", $props, $name)` -- filters the properties for any widget, regardless of type
* `apply_filters("imposer_widget_$type", $props, $name)` -- filters the properties for widgets of type `$type`.

These filters can then do any necessary lookups and replace the alternate identifiers with database ids as needed.

(Implementation note: if you need to know the Wordpress ID of an imposed widget, the option `imposer_widget_ids` is a map from imposer widget names to Wordpress widget IDs, that is automatically updated whenever .)

## API

Note: the specification object read by imposer tasks is built up from nothing on each imposer run, and only contains values put there by the state modules loaded *during that imposer run*.  It does *not* contain any existing plugin or option settings, etc.  If you need to read the existing-in-Wordpress values of such things, you must use PHP code that invokes the Wordpress API.  Think of the specification object as a to-do list or list of "things we'd like to ensure are this way in Wordpress as of this run".

### Actions and Filters

For plugins and PHP blocks within state files, imposer offers the following actions and filters (listed in execution order):

* `do_action("imposer_tasks")` -- an opportunity for plugins or WP-CLI packages to directly register tasks, resources, models, lookups, etc. with imposer, rather than via a state module.
* `apply_filters("imposer_spec_$propName", $value, $spec)` -- each top-level property of the JSON configuration map is passed through a filter named `imposer_spec_PROP`, where `PROP` is the property name.  So for example, if you want to alter the `options` or `plugins` imposer will apply, you can add filters to `imposer_spec_options` and `imposer_spec_plugins`.  The first argument is the value of the key, the second is the current contents of the overall configuration map.
* `apply_filters("imposer_spec", $spec)` -- add a filter to this hook to make any changes to the configuration map that span multiple keys: the individual keys will have already been modified by the preceding `imposer_spec_KEY` filters.

### Event Hooks

In additon to its PHP actions and filters, Imposer offers a system of event hooks for `shell` code.  State files can use the [bashup events](https://github.com/bashup/events/) API to register bash functions that will then be called when specific events are fired.  For example:

```shell
my_plugin.message() { echo "$@"; }
my_plugin.handle_json() { echo "The JSON configuration is:"; echo "$IMPOSER_JSON"; }

event on "after_module"                my_plugin.message "The current state module ($IMPOSER_MODULE) is finished loading."
event on "module loaded" @1            my_plugin.message "Just loaded a module called:"
event on "module loaded this/that"     my_plugin.message "Module 'this/that' has been loaded"
event on "persistent_modules_loaded"   my_plugin.message "The project configuration has been loaded."
event on "all_modules_loaded"          my_plugin.message "All modules have finished loading."
event on "before_apply"                my_plugin.handle_json
event on "after_apply"                 my_plugin.message "All PHP code has been run."
event on "block of css for mytheme" @4 my_plugin.message "Got CSS for mytheme:"
```

The system is very similar to Wordpress actions, except there is no priority system, and you specify the number of *additional* arguments your function takes by adding a `@` and a number before the callback.  (So above, the `module loaded` event will pass up to one argument to `my_plugin.message` in addition to `"Just loaded a module called:"`, which in this case will be the name of the state module loaded.)

Also, you can put arguments after the name of your function, and any arguments supplied by the event will be added after those. Duplicate registrations have no effect, but you can register the same function multiple times for the same event if it has different arguments or a different argument count.

Imposer currently offers the following built-in events:

* `block of `*language-tag* -- emitted when a state module executes a markdown block whose language is not one that imposer natively supports (e.g. json, yaml, etc.).  So a `css` block will emit a `block of css` event that can be hooked by other state modules to update the specification with the supplied data.  The event is passed four arguments: the block contents, the module where the block was found, the module's filename (as of compilation time) and the block's line number within that file.

* `after_module` -- fires when the *currently loading* state module (and all its dependencies) have finished loading.  (Note that the "currently loading" module is not necessarily the same as the module where a callback is being registered, which means that state module can define APIs that register callbacks to run when the *calling* state module is finished loading.)

* `module loaded` *modulename sourcefile*-- emitted when *any* module has finished loading.  Callbacks can register to receive up to two arguments: the module's name and the path to the source file it was loaded from.

* `module loaded `*modulename* -- a [promise-like event](https://github.com/bashup/events/#promise-like-events) that's resolved when the named state is loaded.  If you register a callback before the module is loaded, it will be called if/when the module is loaded later.  But if you register a callback *after* the module is already loaded, the callback will run immediately.  This allows you to have "addon" code or configuration that's only included if some other module is loaded, e.g.:

  ```shell
  # If some other state loads "otherplugin/something", load our addon for it:
  event on "module loaded otherplugin/moduleX" require "my_plugin/addons/my-moduleX-addon"
  ```

* `persistent_modules_loaded` -- fires after the global and project-specific configuration files have been loaded, along with any states they `require`d.  This event is another promise-like event: you can register for it even after it has already happened, and your callback will be invoked immediately.

  The purpose of this event is to let you disable functionality that should only be available to persistent (i.e. project-defined) states, and not from states added on the command line.

* `all_modules_loaded` -- fires when all state modules are finished loading, but before jq is run to produce the configuration JSON.  You can hook this to add additional data or jq code that will postprocess your configuration in some fashion.

* `before_apply` -- fires after jq has been run, with the JSON configuration in the read-only variable `$IMPOSER_JSON`.  You can hook this event to run shell operations before any PHP code is run.

* `after_apply` -- fires after all imposer's tasks have successfully completed, allowing you to optionally run additional shell commands afterwards.

Of course, just like with Wordpress, you are not restricted to the built-in events!  You can create your own custom events, and trigger them with `event emit`, `event fire`, etc..  (See the [event API docs](https://github.com/bashup/events/#readme) for more info.)

Note: if your state file needs to run shell commands that will change the state of the system in some way, you must **only** run these commands during the `before_apply` or `after_apply` events, so that they are only run by the  `imposer apply` subcommand and not by [diagnostic commands](#diagnostic-commands) like `imposer json` or `imposer php`!

### Option Filtering

To keep `imposer options review` and `imposer options diff` from including options you don't want to monitor (because of "noise" or security concerns), or to designate specific options as JSON values, you can use the following API functions from `shell` blocks in your project or state modules:

* `exclude-options` *option-name...* -- Exclude one or more named options from `imposer options` commands, e.g. `exclude-options _edd_table_check`.  You can exclude *part* of an option by using a dotted path, e.g. `foo.timestamp` to exclude the `timestamp` item under the `foo` option.

  Note: if an option name (or portion thereof) contains characters other than A-Z, a-z, 0-9, or `_`, you must surround it in double quotes within the name, and then surround the whole thing with single quotes, e.g.:

  ~~~shell
  exclude-options '"option-with-dashes"."key with spaces".ordinary_key'
  ~~~

  This will exclude the `ordinary_key` part of `key with spaces` within `option-with-dashes`.

  `exclude-options X Y Z` is shorthand for `filter-options 'del(.X, .Y, .Z)'`

* `filter-options` *jq-filter-expression* -- Use *jq-filter-expression* to filter the options before they're displayed or used by `imposer options` commands.  The filter expression will receive a JSON object whose top-level keys are  options, and the output must be in the same format.

* `json-options` *option-name...* -- designate the specified options (or portions thereof) as being JSON strings in the database, but nested objects in the specification.  This lets you work more easily with options like `wpassetcleanup_settings` that are stored as large JSON strings that would be awkward (and non-modular) to impose as a single large string value.

  When this directive is used in a state module, the targeted options or sub-options can be set to any JSON-compatible values in state modules, and will show up the same way to all of the `imposer options` subcommands (e.g. `diff`, `yaml`, `list`, `jq`, and `review`) , but the actual value stored in the database will be encoded as a JSON string.

  As with `exclude-options`, the option names must be single *and* double-quoted if any portions contain characters that are invalid in a JQ attribute name.

You can exclude or JSONify as many options (or add as many filters) as you wish, from any state module.  This allows you to have the state module for a particular theme or plugin add exclusions, filters, or JSONification for the corresponding options or portions thereof.

## Project Status

Currently, this project is still under development, and is not at 100% documentation or test coverage yet.  There is a [roadmap for version 1.0](https://github.com/dirtsimple/imposer/projects/1) tracking the status of these and other issues.

### Performance Notes

While imposer is not generally performance-critical, you may be running it a lot during development, and a second or two of run time can add up quickly during rapid development.  If you are experiencing slow run times, you may wish to note that:

* The slowest part of the process may well be typing out `imposer apply`!... so try using a file watcher like [entr](http://eradman.com/entrproject/) or [modd](https://github.com/cortesi/modd) to automatically run it when files change.
* Due to limitations of the Windows platform, bash scripts like imposer run painfully slowly under Cygwin.  If possible, use a VM, Docker container, or the Linux Subsystem for Windows to get decent performance.
* On average, Imposer spends most of its execution time running large php programs (`composer` and `wp`) from the command line, so [enabling the CLI opcache](https://pierre-schmitz.com/using-opcache-to-speed-up-your-cli-scripts/) will help a lot.
* Currently, calculating the default `IMPOSER_PATH` is slow because it runs `wp` and `composer` up to three times each.  You can speed this up considerably by supplying an explicit `IMPOSER_PATH`, or at least the individual directories such as `IMPOSER_PLUGINS`.  (You can run `imposer path` to find out the directories imposer is currently using, or `imposer default-path` to get the directories imposer would use if `IMPOSER_PATH` were not set.)
* By default, the compiled version of state files are cached in `imposer/.cache` in your project root.  You can change this by setting `IMPOSER_CACHE` to the desired directory.  (Note, however, that this directory *must* be different for each Wordpress instance, or data corruption may occur.)
* In situations where caching is disabled, or your cache is frequently cleared, YAML blocks are compiled more slowly than JSON blocks, as a PHP script is run for each YAML block in each uncached file.  If  you have lots of YAML blocks in one file, you may wish to split the module into smaller pieces (as the unchanged modules will be cached), or use JSON blocks instead (as there is no conversion overhead).
* wp-cli commands are generally slow to start: if you have a choice between running wp-cli from a `shell` block, or writing PHP code directly, the latter is considerably faster.

