# General Tests

````sh
# Load functions and turn off error exit
    $ source "$TESTDIR/../imposer.md"; set +e

# Mock wp and composer
    $ exec 9>&2; export PATH="$TESTDIR/mocks:$PATH"
````

## Core Configuration

### File and Function Names

````sh
# Make . our project root
    $ cat >imposer-project.md <<'EOF'
    > ```shell
    > echo "hello from imposer-project.md!"
    > ```
    > EOF

# Ignore/null out site-wide configuration for testing
    $ loco_user_config() { :; }
    $ loco_site_config() { :; }
    $ imposer.no-op() { :;}
    $ loco_main no-op
    hello from imposer-project.md!

# Project directory should be current directory
    $ [[ "$LOCO_ROOT" == "$PWD" ]] || echo fail
````

### State Directories

State files are searched for in `IMPOSER_PATH` -- a `:`-separated string of directory names.  If no `IMPOSER_PATH` is set, one is generated that consists of:

* `./imposer`
* The Wordpress themes directory (e.g. `./wp-content/themes/`)
* The Wordpress plugin directory (e.g. `./wp-content/plugins/`)
* The `composer config vendor-dir`, if `composer.json` is present (e.g. `./vendor/`)
* The wp-cli package path (typically `~/.wp-cli/packages`)
* The global composer `vendor` directory, e.g. `${COMPOSER_HOME}/vendor`.

#### `path` and `default-path`

You can run `imposer path` and `imposer default-path` to get the current set of module directories or the default set of directories, respectively:

````sh
# Default order is imposer, wp themes + plugins, composer local, wp packages, composer global:
    $ echo '{}' >composer.json
    $ mkdir -p imposer themes plugins packages/vendor vendor COMPOSER_GLOBAL_VENDOR
    $ (REPLY="$(imposer path)"; echo "${REPLY//"$PWD"/.}")
    ./imposer:./themes:./plugins:./vendor:./packages/vendor:./COMPOSER_GLOBAL_VENDOR

# But can be overrriden by IMPOSER_PATH
    $ IMPOSER_PATH=vendor:imposer
    $ (REPLY="$(imposer path)"; echo "${REPLY//"$PWD"/.}")
    ./vendor:./imposer

# Unless you're looking at the default path (which ignores IMPOSER_PATH)
    $ (REPLY="$(imposer default-path)"; echo "${REPLY//"$PWD"/.}")
    ./imposer:./themes:./plugins:./vendor:./packages/vendor:./COMPOSER_GLOBAL_VENDOR

# Only directories that exist are included, however:
    $ rmdir COMPOSER_GLOBAL_VENDOR themes
    $ (REPLY="$(imposer default-path)"; echo "${REPLY//"$PWD"/.}")
    ./imposer:./plugins:./vendor:./packages/vendor

# And vendor/ is only included if there's a `composer.json`:
    $ rm composer.json
    $ (REPLY="$(imposer default-path)"; echo "${REPLY//"$PWD"/.}")
    ./imposer:./plugins:./packages/vendor
    $ echo '{}' >composer.json

# Once calculated, the internal path remains the same:
    $ IMPOSER_PATH=
    $ imposer path
    */imposer:*/plugins:*/vendor:*/packages/vendor (glob)

# even if IMPOSER_PATH changes, or a directory is removed:
    $ IMPOSER_PATH=vendor:imposer
    $ rmdir packages/vendor
    $ imposer path
    */imposer:*/plugins:*/vendor:*/packages/vendor (glob)

# But the default is still the default, and calculated "fresh":
    $ imposer default-path
    */imposer:*/plugins:*/vendor (glob)

# Reset for other tests
    $ unset IMPOSER_PATH
    $ imposer_dirs=()
````

## State Handling

### JSON and YAML

### Imposing State Modules

State modules are imposed by sourcing the compiled form of their `.state.md` file, at most once.  Modules can require other modules by calling `require` with one or more module names.

````sh
# Mock have_module and __load_module
    $ old_module_funcs="$(declare -f have_module __load_module)"
    $ __load_module() { echo "load module:" "$@"; }
    $ have_module() { REPLY="found/$1"; echo "find module:" "$@"; }

# require loads the named module only once
    $ require fizz/buzz
    find module: fizz/buzz
    load module: fizz/buzz found/fizz/buzz
    $ require fizz/buzz

# infinite recursion is prevented
    $ __load_module() { echo "loading: $1"; require whiz/bang; }
    $ require ping/pong
    find module: ping/pong
    loading: ping/pong
    find module: whiz/bang
    loading: whiz/bang

# failure to find a state produces an error
    $ have_module() { false; }
    $ (require cant/find)
    Could not find module cant/find in /*/imposer /*/plugins /*/vendor (glob)
    [64]

# Restore have_module and __load_module
    $ eval "$old_module_funcs"
````

#### Module Lookup

Modules are looked up in each directory on the imposer path, checking for files in the exact directory  or specific sub-locations thereof:

````sh
# Mock file search function to output dir and files searched
    $ old_rie="$(declare -f reply_if_exists)"
    $ reply_if_exists() {
    >     [[ $1 == "$PWD/"* ]] || echo "invalid directory: $1"
    >     echo -n "${1/#$PWD/.} "; shift
    >     for REPLY; do [[ $REPLY == *.state.md ]] || echo "invalid filename: $REPLY"; done
    >     echo "${@%.state.md}"; false
    > }

    $ imposer path
    */imposer:*/plugins:*/vendor (glob)

# Paths for an unprefixed name:
    $ have_module baz
    ./imposer baz baz/default baz/imposer-states/default ./imposer-states/baz
    ./plugins baz baz/default baz/imposer-states/default ./imposer-states/baz
    ./vendor baz baz/default baz/imposer-states/default ./imposer-states/baz
    [1]

    $ have_module bar/baz
    ./imposer bar/baz bar/baz/default bar/baz/imposer-states/default bar/imposer-states/baz
    ./plugins bar/baz bar/baz/default bar/baz/imposer-states/default bar/imposer-states/baz
    ./vendor bar/baz bar/baz/default bar/baz/imposer-states/default bar/imposer-states/baz
    [1]

    $ have_module foo/bar/baz
    ./imposer foo/bar/baz foo/bar/baz/default foo/bar/baz/imposer-states/default foo/bar/imposer-states/baz
    ./plugins foo/bar/baz foo/bar/baz/default foo/bar/baz/imposer-states/default foo/bar/imposer-states/baz
    ./vendor foo/bar/baz foo/bar/baz/default foo/bar/baz/imposer-states/default foo/bar/imposer-states/baz
    [1]

# Un-mock reply_if_exists
    $ eval "$old_rie"

# Non-existent module, return false:
    $ (have_module x)
    [1]

# In last directory, name as file under imposer
    $ mkdir -p vendor/imposer-states
    $ touch vendor/imposer-states/x.state.md
    $ (have_module x && echo "$REPLY")
    /*/vendor/./imposer-states/x.state.md (glob)

# Override w/directory:
    $ mkdir -p vendor/x/imposer-states/
    $ touch vendor/x/imposer-states/default.state.md
    $ (have_module x && echo "$REPLY")
    /*/vendor/x/imposer-states/default.state.md (glob)

# Removing it exposes the previous file again
    $ rm vendor/x/imposer-states/default.state.md
    $ (have_module x && echo "$REPLY")
    /*/vendor/./imposer-states/x.state.md (glob)

# Found location is cached for current subshell
    $ have_module x && echo "$REPLY"
    /*/vendor/./imposer-states/x.state.md (glob)

    $ touch vendor/x/imposer-states/default.state.md
    $ have_module x && echo "$REPLY"
    /*/vendor/./imposer-states/x.state.md (glob)

# Or lack-of-location, if applicable:
    $ have_module y && echo "$REPLY"
    [1]

    $ touch imposer/y.state.md
    $ have_module y && echo "$REPLY"
    [1]

````

#### State Loading

And then loaded by compiling the markdown source, optionally caching in the  `$IMPOSER_CACHE` directory (unless `IMPOSER_CACHE` is set to an empty string)

````sh
# Test cache generation
    $ cat >imposer/load-test.state.md <<'EOF'
    > ```shell
    > echo "loading load-test from $__DIR__"
    > EOF

    $ @require "test-this:load-test" __load_module load-test imposer/load-test.state.md
    loading load-test from imposer

    $ cat imposer/.cache/load-test
    echo "loading load-test from $__DIR__"

# No caching if IMPOSER_CACHE is empty:
    $ rm imposer/.cache/load-test
    $ IMPOSER_CACHE= __load_module load-test imposer/load-test.state.md
    loading load-test from imposer
    event "module_loaded_load-test" already resolved
    [70]
    $ cat imposer/.cache/load-test
    cat: *imposer/.cache/load-test*: No such file or directory (glob)
    [1]
````

### Processing JSON and PHP

After all required state modules have been sourced, the accumulated YAML, JSON, and jq code they supplied is executed, to produce a JSON specification object.  All of the PHP code defined by this file and the state modules is then run, with the JSON specification piped in for processing.

````sh
# Running `imposer apply` calls `wp eval-file` with the accumulated JSON and PHP:
    $ event on "all_modules_loaded" echo "EVENT: all_modules_loaded"
    $ event on "before_apply" echo "EVENT: before_apply"
    $ event on "after_apply" echo "EVENT: after_apply"
    $ imposer apply --quiet --debug=imposer-tasks
    EVENT: all_modules_loaded
    EVENT: before_apply
    --- Options: ---
    --quiet
    --debug=imposer-tasks
    --- JSON: ---
    {"options":{},"plugins":{"imposer-tweaks":false}}
    --- PHP: ---
    <?php
    EVENT: after_apply

# Running apply resets the filters and events, so doing it again is a no-op:
    $ imposer apply
````

#### Test Fixtures

##### YAML

```yaml
options:
  wp_mail_smtp:
    mail:
      from_email: \(env.WP_FROM_EMAIL // _)
      from_name:  \(env.WP_FROM_NAME // _)
      mailer: mailgun
      return_path: true
    mailgun:
      api_key: \(env.MAILGUN_API_KEY)
      domain: \(env.MAILGUN_API_DOMAIN)
plugins:
  wp_mail_smtp:      # if a value is omitted or true, the plugin is activated
  disable_me: false  # if the value is explicitly `false`, deactivate the plugin
my_ecommerce_plugin:
  products: {}   # empty maps for other state modules to insert configuration into
  categories: {}
```
##### jq

```jq
.plugins."some-plugin" = true  # activate `some-plugin`
```
##### PHP

```php
function my_ecommerce_plugin_impose($data) {
	MyPluginAPI::setup_products($data['products']);
	MyPluginAPI::setup_categories($data['categories']);
}

add_action('imposer_impose_my_ecommerce_plugin', 'my_ecommerce_plugin_impose', 10, 1);
```
##### PHP Tweaks

```php tweak
add_filter('made_up_example', '__return_true');
```
##### Shell

```shell
# Load a required modules before proceeding
require "some/state"

# Use `have_module` to test for availability
if have_module "foo/other"; then
    require "foo/other" "this/that"
fi

my_plugin.message() { echo "$@"; }
my_plugin.handle_json() { echo "The JSON configuration is:"; echo "$IMPOSER_JSON"; }

event on "after_module"              my_plugin.message "The current state module ($IMPOSER_MODULE) is finished loading."
event on "module_loaded" @1          my_plugin.message "Just loaded a module called:"
event on "module_loaded_this/that"   my_plugin.message "Module 'this/that' has been loaded"
event on "persistent_modules_loaded" my_plugin.message "The project configuration has been loaded."
event on "all_modules_loaded"        my_plugin.message "All modules have finished loading."
event on "before_apply"              my_plugin.handle_json
event on "after_apply"               my_plugin.message "All PHP code has been run."
event on "block of css for mytheme"  @_ my_plugin.message "Got CSS for mytheme:"
```
##### Misc.

```css for mytheme
/* CSS for mytheme */
```

#### Dumping JSON or PHP

The `imposer json` and `imposer php` commands process state modules and then output the resulting JSON or PHP without running the PHP.  (Any shell code in the modules is still executed, however.)

````sh
# Set up to run fixtures from this file:
    $ cp $TESTDIR/$TESTFILE imposer/dummy.state.md
    $ mkdir imposer/some; touch imposer/some/state.state.md
    $ mkdir imposer/foo; touch imposer/foo/other.state.md
    $ mkdir imposer/this; touch imposer/this/that.state.md
    $ export WP_FROM_EMAIL=foo@bar.com
    $ export MAILGUN_API_KEY=madeup\"key MAILGUN_API_DOMAIN=madeup.domain

# Run the version of imposer under test:
    $ imposer-cmd() { command imposer "$@"; }

# JSON dump:
    $ IMPOSER_PATH=imposer imposer-cmd json dummy --require=foo.php
    hello from imposer-project.md!
    Module 'this/that' has been loaded
    The project configuration has been loaded.
    Got CSS for mytheme: /* CSS for mytheme */
     dummy */Basics.cram.md/imposer/dummy.state.md 332 (glob)
    warning: module 'dummy' contains PHP tweaks that will not be loaded; tweaks must be defined in the project or global configuration.
    The current state module (dummy) is finished loading.
    Just loaded a module called: dummy
    All modules have finished loading.
    {
      "options": {
        "wp_mail_smtp": {
          "mail": {
            "from_email": "foo@bar.com",
            "from_name": "",
            "mailer": "mailgun",
            "return_path": true
          },
          "mailgun": {
            "api_key": "madeup\"key",
            "domain": "madeup.domain"
          }
        }
      },
      "plugins": {
        "imposer-tweaks": false,
        "disable_me": false,
        "wp_mail_smtp": null,
        "some-plugin": true
      },
      "my_ecommerce_plugin": {
        "categories": {},
        "products": {}
      }
    }

    $ export WP_FROM_NAME="Me"

# PHP dump:
    $ IMPOSER_PATH=imposer imposer-cmd php dummy
    hello from imposer-project.md!
    Module 'this/that' has been loaded
    The project configuration has been loaded.
    Got CSS for mytheme: /* CSS for mytheme */
     dummy */Basics.cram.md/imposer/dummy.state.md 332 (glob)
    warning: module 'dummy' contains PHP tweaks that will not be loaded; tweaks must be defined in the project or global configuration.
    The current state module (dummy) is finished loading.
    Just loaded a module called: dummy
    All modules have finished loading.
    <?php
    function my_ecommerce_plugin_impose($data) {
    	MyPluginAPI::setup_products($data['products']);
    	MyPluginAPI::setup_categories($data['categories']);
    }
    
    add_action('imposer_impose_my_ecommerce_plugin', 'my_ecommerce_plugin_impose', 10, 1);

# Sources dump:
    $ IMPOSER_PATH=imposer imposer-cmd sources dummy
    hello from imposer-project.md!
    warning: module 'dummy' contains PHP tweaks that will not be loaded; tweaks must be defined in the project or global configuration.
    imposer-project.md
    imposer/dummy.state.md
    imposer/some/state.state.md
    imposer/foo/other.state.md
    imposer/this/that.state.md

# And just for the heck of it, show all the events:
    $ wp() { echo wp "$@"; cat >/dev/null; }; export -f wp
    $ IMPOSER_PATH=imposer imposer-cmd apply dummy --color
    hello from imposer-project.md!
    Module 'this/that' has been loaded
    The project configuration has been loaded.
    Got CSS for mytheme: /* CSS for mytheme */
     dummy */Basics.cram.md/imposer/dummy.state.md 332 (glob)
    warning: module 'dummy' contains PHP tweaks that will not be loaded; tweaks must be defined in the project or global configuration.
    The current state module (dummy) is finished loading.
    Just loaded a module called: dummy
    All modules have finished loading.
    The JSON configuration is:
    {"options":{"wp_mail_smtp":{"mail":{"from_email":"foo@bar.com","from_name":"Me","mailer":"mailgun","return_path":true},"mailgun":{"api_key":"madeup\"key","domain":"madeup.domain"}}},"plugins":{"imposer-tweaks":false,"disable_me":false,"wp_mail_smtp":null,"some-plugin":true},"my_ecommerce_plugin":{"categories":{},"products":{}}}
    wp eval dirtsimple\imposer\Imposer::run_stream("php://fd/7"); --color
    All PHP code has been run.

````
### PHP Tweaks

````sh
    $ unset -f wp
    $ ls plugins
    $ rmdir plugins

    $ cat >>imposer-project.md <<'EOF'
    > ```shell
    > require dummy
    > event off "before_apply" my_plugin.handle_json
    > ```
    > EOF
    $ IMPOSER_PATH=imposer imposer-cmd apply
    hello from imposer-project.md!
    Module 'this/that' has been loaded
    Got CSS for mytheme: /* CSS for mytheme */
     dummy */Basics.cram.md/imposer/dummy.state.md 332 (glob)
    The current state module (dummy) is finished loading.
    Just loaded a module called: dummy
    Just loaded a module called: imposer-project
    The project configuration has been loaded.
    All modules have finished loading.
    --- JSON: ---
    {"options":{"wp_mail_smtp":{"mail":{"from_email":"foo@bar.com","from_name":"Me","mailer":"mailgun","return_path":true},"mailgun":{"api_key":"madeup\"key","domain":"madeup.domain"}}},"plugins":{"imposer-tweaks":true,"disable_me":false,"wp_mail_smtp":null,"some-plugin":true},"my_ecommerce_plugin":{"categories":{},"products":{}}}
    --- PHP: ---
    <?php
    function my_ecommerce_plugin_impose($data) {
    	MyPluginAPI::setup_products($data['products']);
    	MyPluginAPI::setup_categories($data['categories']);
    }
    
    add_action('imposer_impose_my_ecommerce_plugin', 'my_ecommerce_plugin_impose', 10, 1);
    All PHP code has been run.

    $ ls plugins
    imposer-tweaks.php

    $ cat plugins/imposer-tweaks.php
    <?php
    # Plugin Name:  Imposer Tweaks
    # Plugin URI:   https://github.com/dirtsimple/imposer#adding-code-tweaks
    # Description:  Automatically-generated from tweaks in imposer state modules
    # Version:      0.0.0
    # Author:       Various
    # License:      Unknown
    
    add_filter('made_up_example', '__return_true');

    $ IMPOSER_PATH=imposer imposer-cmd tweaks
    hello from imposer-project.md!
    Module 'this/that' has been loaded
    Got CSS for mytheme: /* CSS for mytheme */
     dummy */Basics.cram.md/imposer/dummy.state.md 332 (glob)
    The current state module (dummy) is finished loading.
    Just loaded a module called: dummy
    Just loaded a module called: imposer-project
    The project configuration has been loaded.
    All modules have finished loading.
    <?php
    # Plugin Name:  Imposer Tweaks
    # Plugin URI:   https://github.com/dirtsimple/imposer#adding-code-tweaks
    # Description:  Automatically-generated from tweaks in imposer state modules
    # Version:      0.0.0
    # Author:       Various
    # License:      Unknown
    
    add_filter('made_up_example', '__return_true');

    $ IMPOSER_PATH=imposer imposer-cmd tweaks testme
    hello from imposer-project.md!
    Module 'this/that' has been loaded
    Got CSS for mytheme: /* CSS for mytheme */
     dummy */Basics.cram.md/imposer/dummy.state.md 332 (glob)
    The current state module (dummy) is finished loading.
    Just loaded a module called: dummy
    Just loaded a module called: imposer-project
    The project configuration has been loaded.
    `imposer tweaks` does not accept arguments
    [64]
````