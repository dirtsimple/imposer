# `imposer`: Impose States on a Wordpress Instance

Imposer is a modular configuration manager for Wordpress, allowing state information from outside the database (i.e. files, environment variables, etc.) to be "imposed" upon a Wordpress instance's database.  State information is defined in markdown files (like this one) containing mixed shell script, jq code, YAML, and PHP.

Imposer is built using [mdsh](https://github.com/bashup/mdsh), combining [loco](https://github.com/bashup/loco) for command-line parsing and configuration loading, and [jqmd](https://github.com/bashup/jqmd) for literate programming support.

```shell mdsh
@module imposer.md
@main loco_main

@import pjeby/license @comment    LICENSE
@import bashup/jqmd   mdsh-source "$BASHER_PACKAGES_PATH/bashup/jqmd/jqmd.md"
@import bashup/loco   mdsh-source "$BASHER_PACKAGES_PATH/bashup/loco/loco.md"
```

In addition to source code, this file also contains cram-based unit tests:

````sh
# Load functions and turn off error exit
    $ source jqmd; run-markdown "$TESTDIR/$TESTFILE"; set +e
````

## Core Configuration

### File and Function Names

Configuration is loaded using loco.   Subcommand functions are named `imp.X`, where `X` is the command or option name.

```shell
loco_preconfig() {
    LOCO_FILE=("composer.json" "wp-cli.yml")
    LOCO_NAME=imposer
    LOCO_USER_CONFIG=$HOME/.config/imposer.md
    LOCO_SITE_CONFIG=/etc/imposer.md
}

loco_site_config() { run-markdown "$1"; }
loco_user_config() { run-markdown "$1"; }
loco_loadproject() { cd "$LOCO_ROOT"; }
```

````sh
# Make . our project root
    $ echo '{}' >composer.json

# Ignore/null out site-wide configuration for testing
    $ loco_user_config() { :; }
    $ loco_site_config() { :; }
    $ imposer.no-op() { :;}
    $ loco_main no-op

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

```shell
imposer_dirs=()

get_imposer_dirs() {
    if [[ ${imposer_dirs[@]+_} ]]; then
        return
    elif (($#)); then
        true
    elif [[ ${IMPOSER_PATH-} ]]; then
        IFS=: eval 'set -- $IMPOSER_PATH'
    else
        set -- imposer "$(wp theme path)" "$(wp plugin path)" \
            "$( [[ -f composer.json ]] &&  composer config --absolute vendor-dir)" \
            "$(wp package path)" "$(composer global config --absolute vendor-dir)"
    fi
    imposer_dirs=()
    for REPLY; do
        if [[ "$REPLY" && -d "$REPLY" ]]; then
            [[ "$REPLY" == /* ]] || realpath.absolute "$REPLY"
            imposer_dirs+=("${REPLY%/}")
        fi
    done
} 2>/dev/null
```

````sh
# Mock wp and composer
    $ exec 9>&2;
    $ wp() {
    >     case "$*" in
    >         "theme path") echo "themes";;
    >         "plugin path") echo "plugins";;
    >         "package path") echo "packages";;
    >         "eval-file - "*)
    >             echo "--- JSON: ---"; printf '%s\n' "${@:3}"
    >             echo "--- PHP: ---"; cat
    >             ;;
    >         *) echo "unexpected wp $*" >&9; exit 64 ;;
    >     esac
    > }
    $ composer() {
    >     case "$*" in
    >         "global config --absolute vendor-dir") echo "COMPOSER_GLOBAL_VENDOR";;
    >         "config --absolute vendor-dir") echo "vendor";;
    >         *) echo "unexpected composer $*" >&2; exit 64 ;;
    >     esac
    > }
````

#### `path` and `default-path`

You can run `imposer path` and `imposer default-path` to get the current set of state directories or the default set of directories, respectively:

```shell
imposer.path() {
    get_imposer_dirs;
    if [[ ${imposer_dirs[@]+_} ]]; then IFS=: eval 'echo "${imposer_dirs[*]}"'; fi
}

imposer.default-path() { local imposer_dirs=() IMPOSER_PATH=; imposer path; }
```

````sh
# Default order is imposer, wp themes + plugins, composer local, wp packages, composer global:
    $ mkdir imposer themes plugins packages vendor COMPOSER_GLOBAL_VENDOR
    $ (REPLY="$(imposer path)"; echo "${REPLY//"$PWD"/.}")
    ./imposer:./themes:./plugins:./vendor:./packages:./COMPOSER_GLOBAL_VENDOR

# But can be overrriden by IMPOSER_PATH
    $ IMPOSER_PATH=vendor:imposer
    $ (REPLY="$(imposer path)"; echo "${REPLY//"$PWD"/.}")
    ./vendor:./imposer

# Unless you're looking at the default path (which ignores IMPOSER_PATH)
    $ (REPLY="$(imposer default-path)"; echo "${REPLY//"$PWD"/.}")
    ./imposer:./themes:./plugins:./vendor:./packages:./COMPOSER_GLOBAL_VENDOR

# Only directories that exist are included, however:
    $ rmdir COMPOSER_GLOBAL_VENDOR themes
    $ (REPLY="$(imposer default-path)"; echo "${REPLY//"$PWD"/.}")
    ./imposer:./plugins:./vendor:./packages

# And vendor/ is only included if there's a `composer.json`:
    $ rm composer.json
    $ (REPLY="$(imposer default-path)"; echo "${REPLY//"$PWD"/.}")
    ./imposer:./plugins:./packages
    $ echo '{}' >composer.json

# Once calculated, the internal path remains the same:
    $ IMPOSER_PATH=
    $ imposer path
    */imposer:*/plugins:*/vendor:*/packages (glob)

# even if IMPOSER_PATH changes, or a directory is removed:
    $ IMPOSER_PATH=vendor:imposer
    $ rmdir packages
    $ imposer path
    */imposer:*/plugins:*/vendor:*/packages (glob)

# But the default is still the default, and calculated "fresh":
    $ imposer default-path
    */imposer:*/plugins:*/vendor (glob)

# Reset for other tests
    $ unset IMPOSER_PATH
    $ imposer_dirs=()
````

## State Handling

### JSON and YAML

The default state map begins with an empty options and plugins map:

```yaml
options: {}
plugins: {}
```

which is then processed from PHP to modify wordpress options and plugins:

```php
$state = json_decode($args[0], true);

foreach ( $state['options'] as $opt => $new) {
	$old = get_option($opt);
	if (is_array($old) && is_array($new)) $new = array_replace_recursive($old, $new);
	if ($new !== $old) {
		if ($old === false) add_option($opt, $new); else update_option($opt, $new);
	}
}

if ( !empty( $plugins = $state['plugins'] ) ) {
	$fetcher = new \WP_CLI\Fetchers\Plugin;
	$plugin_files = array_column( $fetcher->get_many(array_keys($plugins)), 'file', 'name' );
	$activate = $deactivate = [];
	foreach ($plugins as $plugin => $desired) {
		$desired = ($desired !== false);
		if ( empty($plugin_files[$plugin]) ) {
			continue; # XXX warn plugin of that name isn't installed
		}
		if ( is_plugin_active($plugin_files[$plugin]) == $desired ) continue;
		if ( $desired ) {
			$activate[] = $plugin_files[$plugin];
		} else {
			$deactivate[] = $plugin_files[$plugin];
		}
	}
	deactivate_plugins($deactivate);  # deactivate first, in case of conflicts
	activate_plugins($activate);
}
```

````sh
# For testing purposes, save the PHP runtime code, and then replace it with a placeholder:
    $ printf -v PHP_RUNTIME '%s\n' "${mdsh_raw_php[@]}"
    $ mdsh_raw_php=($'# imposer runtime goes here\n')
````

### Imposing Named States

States are imposed by sourcing the compiled form of their `.state.md` file, at most once.  States can require other states by calling `require` with one or more state names.

```shell
imposed_states=
require() {
    get_imposer_dirs
    while (($#)); do
        if ! [[ $imposed_states == *"<$1>"* ]]; then
            imposed_states+="<$1>"
            if __find_state "$1"; then
                __load_state "$1" "$REPLY"
            else loco_error "Could not find state $1 in ${imposer_dirs[*]}"
            fi
        fi
        shift
    done
}
```

````sh
# Mock __find_state and __load_state
    $ old_states="$(declare -f __find_state __load_state)"
    $ __load_state() { echo "load state:" "$@"; }
    $ __find_state() { REPLY="found/$1"; echo "find state:" "$@"; }

# require loads the named state only once
    $ require fizz/buzz
    find state: fizz/buzz
    load state: fizz/buzz found/fizz/buzz
    $ require fizz/buzz

# infinite recursion is prevented
    $ __load_state() { echo "loading: $1"; require whiz/bang; }
    $ require ping/pong
    find state: ping/pong
    loading: ping/pong
    find state: whiz/bang
    loading: whiz/bang

# failure to find a state produces an error
    $ __find_state() { false; }
    $ (require cant/find)
    Could not find state cant/find in /*/imposer /*/plugins /*/vendor (glob)
    [64]

# Restore __find_state and __load_state
    $ eval "$old_states"
````

#### State File Lookup

States are looked up in each directory on the imposer path, checking for files in the exact directory  or specific sub-locations thereof:

```shell
__find_state() {
    realpath.basename "$1"; local name=$REPLY
    realpath.dirname "$1"; local ns=$REPLY
    local patterns=("$1" "$1/default" "$1/imposer/default" "$ns/imposer/$name" )
    for REPLY in ${imposer_dirs[@]+"${imposer_dirs[@]}"}; do
        if reply_if_exists "$REPLY" "${patterns[@]/%/.state.md}"; then return; fi
    done
    false
}
```

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
    $ __find_state baz
    ./imposer baz baz/default baz/imposer/default ./imposer/baz
    ./plugins baz baz/default baz/imposer/default ./imposer/baz
    ./vendor baz baz/default baz/imposer/default ./imposer/baz
    [1]

    $ __find_state bar/baz
    ./imposer bar/baz bar/baz/default bar/baz/imposer/default bar/imposer/baz
    ./plugins bar/baz bar/baz/default bar/baz/imposer/default bar/imposer/baz
    ./vendor bar/baz bar/baz/default bar/baz/imposer/default bar/imposer/baz
    [1]

    $ __find_state foo/bar/baz
    ./imposer foo/bar/baz foo/bar/baz/default foo/bar/baz/imposer/default foo/bar/imposer/baz
    ./plugins foo/bar/baz foo/bar/baz/default foo/bar/baz/imposer/default foo/bar/imposer/baz
    ./vendor foo/bar/baz foo/bar/baz/default foo/bar/baz/imposer/default foo/bar/imposer/baz
    [1]

# Un-mock reply_if_exists
    $ eval "$old_rie"

# Non-existent state, return false:
    $ __find_state x
    [1]

# In last directory, name as file under imposer
    $ mkdir -p vendor/imposer
    $ touch vendor/imposer/x.state.md
    $ __find_state x && echo "$REPLY"
    /*/vendor/./imposer/x.state.md (glob)

# Override w/directory:
    $ mkdir -p vendor/x/imposer/
    $ touch vendor/x/imposer/default.state.md
    $ __find_state x && echo "$REPLY"
    /*/vendor/x/imposer/default.state.md (glob)

# Removing it exposes the previous file again
    $ rm vendor/x/imposer/default.state.md
    $ __find_state x && echo "$REPLY"
    /*/vendor/./imposer/x.state.md (glob)

````

#### State Loading

And then loaded by compiling the markdown source, optionally caching in the  `$IMPOSER_CACHE` directory (unless `IMPOSER_CACHE` is set to an empty string)

```shell
__load_state() {
    if [[ ! "${IMPOSER_CACHE-_}" ]]; then
        run-markdown "$2"  # don't cache if IMPOSER_CACHE is an empty string
    else
        mdsh-cache "${IMPOSER_CACHE-$LOCO_ROOT/imposer/.cache}" "$2" "$1" unset -f mdsh:file-header mdsh:file-footer
        source "$REPLY"
    fi
}
```
````sh
# Test cache generation
    $ cat >imposer/load-test.state.md <<'EOF'
    > ```shell
    > echo "loading load-test"
    > EOF

    $ __load_state load-test imposer/load-test.state.md
    loading load-test

    $ cat imposer/.cache/load-test
    echo "loading load-test"

# No caching if IMPOSER_CACHE is empty:
    $ rm imposer/.cache/load-test
    $ IMPOSER_CACHE= __load_state load-test imposer/load-test.state.md
    loading load-test
    $ cat imposer/.cache/load-test
    cat: *imposer/.cache/load-test*: No such file or directory (glob)
    [1]
````

### Processing JSON and PHP

After all required state files have been sourced, the accumulated YAML, JSON, and jq code they supplied is executed, to produce a JSON configuration.  All of the PHP code defined by this file and the state files is then run, with the JSON configuration as the `$state` variable.

```shell
cat-php() { printf '%s\n' '<?php' "${mdsh_raw_php[@]}"; }

imposer.require() {
    require "$@"
    if HAVE_FILTERS; then
        REPLY=$(RUN_JQ -c -n)
        CLEAR_FILTERS  # prevent auto-run to stdout
        cat-php | wp eval-file - "$REPLY"
    fi
}
```

````sh
# Running `imposer require` calls `wp eval-file` with the accumulated JSON and PHP:
    $ imposer require
    --- JSON: ---
    {"options":{},"plugins":{}}
    --- PHP: ---
    <?php
    # imposer runtime goes here
    
# Running require resets the filters, so doing it again is a no-op:
    $ imposer require
````

#### Dumping JSON or PHP

The `imposer json` and `imposer php` commands process state files and then output the resulting JSON or PHP without running the PHP.  (Any shell code in the states is still executed, however.)

```shell
imposer.json() { require "$@"; ! HAVE_FILTERS || RUN_JQ -n; }
imposer.php()  { mdsh_raw_php=(); require "$@"; CLEAR_FILTERS; cat-php; }
```

````sh
# Set up to run examples from README:
    $ cp $TESTDIR/README.md imposer/dummy.state.md
    $ mkdir imposer/some; touch imposer/some/state.state.md
    $ mkdir imposer/foo; touch imposer/foo/other.state.md
    $ mkdir imposer/this; touch imposer/this/that.state.md
    $ export WP_FROM_EMAIL=foo@bar.com WP_FROM_NAME="Me"
    $ export MAILGUN_API_KEY=madeup\"key MAILGUN_API_DOMAIN=madeup.domain

# Run the version of imposer under test:
    $ imposer-cmd() { jqmd -R "$TESTDIR/$TESTFILE" "$@"; }

# JSON dump:
    $ IMPOSER_PATH=imposer imposer-cmd json dummy
    {
      "options": {
        "wp_mail_smtp": {
          "mail": {
            "from_email": "foo@bar.com",
            "from_name": "Me",
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
        "disable_me": false,
        "wp_mail_smtp": null,
        "some-plugin": true
      },
      "my_ecommerce_plugin": {
        "categories": {},
        "products": {}
      }
    }

# PHP dump (includes only state-supplied code, no core code:
    $ IMPOSER_PATH=imposer imposer-cmd php dummy
    <?php
    $my_plugin_info = $state['my_ecommerce_plugin'];
    
    MyPluginAPI::setup_products($my_plugin_info['products']);
    MyPluginAPI::setup_categories($my_plugin_info['categories']);
    
````