#!/usr/bin/env bash
: '
<!-- ex: set syntax=markdown : '; eval "$(jqmd -E "$BASH_SOURCE")"; # -->

# `imposer`: Impose States on a Wordpress Instance

Imposer is a modular configuration manager for Wordpress, allowing state information from outside the database (i.e. files, environment variables, etc.) to be "imposed" upon a Wordpress instance's database.  State information is defined in markdown files (like this one) containing mixed shell script, jq code, YAML, and PHP.

Imposer is built using [mdsh](https://github.com/bashup/mdsh), combining [loco](https://github.com/bashup/loco) for command-line parsing and configuration loading, and [jqmd](https://github.com/bashup/jqmd) for literate programming support.

```shell mdsh
@module imposer.md
@main loco_main

@import pjeby/license @comment    LICENSE
@import bashup/jqmd   mdsh-source "$BASHER_PACKAGES_PATH/bashup/jqmd/jqmd.md"
@import bashup/loco   mdsh-source "$BASHER_PACKAGES_PATH/bashup/loco/loco.md"
@import bashup/events tail -n +2  "$BASHER_PACKAGES_PATH/bashup/events/bashup.events"
```

## Core Configuration

### File and Function Names

Configuration is loaded using loco.  Subcommand functions are named `imp.X`, where `X` is the command or option name.

```shell
loco_preconfig() {
    LOCO_FILE=("imposer-project.md" "composer.json" "wp-cli.yml")
    LOCO_NAME=imposer
    LOCO_USER_CONFIG=$HOME/.config/imposer.md
    LOCO_SITE_CONFIG=/etc/imposer.md
}

loco_site_config() { run-markdown "$1"; }
loco_user_config() { run-markdown "$1"; }
loco_loadproject() {
	cd "$LOCO_ROOT"
	imposed_states+="<imposer-project>"
	[[ $LOCO_PROJECT != *.md ]] || __load_state imposer-project "$LOCO_PROJECT"
	event resolve persistent_states_loaded
}
```

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

#### `path` and `default-path`

You can run `imposer path` and `imposer default-path` to get the current set of state directories or the default set of directories, respectively:

```shell
imposer.path() {
    get_imposer_dirs;
    if [[ ${imposer_dirs[@]+_} ]]; then IFS=: eval 'echo "${imposer_dirs[*]}"'; fi
}

imposer.default-path() { local imposer_dirs=() IMPOSER_PATH=; imposer path; }
```

## State Handling

### PHP Parsing and Output

PHP blocks are syntax-checked at compile time (so that the check is cached).  PHP blocks can be assembled in any array variable; element 0 contains namespaced code, and element 1 contains non-namespaced code that hasn't yet been wrapped in a namespace.  Namespace wrapping is lazy: as many non-namespaced blocks as possible are wrapped in a single large `namespace { }` block, instead of wrapping each block as it comes.

```shell ! echo "$1"; eval "$1"
cat-php() { local v1=$1 v2=$1[1]; compact-php $v1 $v2; printf '<?php\n%s' "${!v1-}${!v2-}"; }

compile-php() {
    if REPLY="$(echo "<?php $2" | php -l 2>&1)"; local s=$?; ((s)); then
        php-error "$REPLY" $s "${3-}"; return
    elif php-uses-namespace "$2"; then
        if [[ $REPLY != { ]]; then
            php-error "Namespaces in PHP blocks must be {}-enclosed" 255 "${3-}"; return
        fi
        printf 'compact-php %s %s[1] force\n' "$1" "$1"
    else
        set -- "$1[1]" "$2"
    fi
    printf '%s+=%q\n' "$1" "$2"
}

php-error() {
    echo "In PHP block ${3:+at line ${3-} }of ${MDSH_SOURCE--}:"$'\n'"$1" >&2; return $2
}

compact-php() {
    if [[ "${!1-}${3-}" && "${!2-}" ]]; then
        printf -v $1 '%snamespace {\n%s}\n' "${!1-}" "${!2}"; unset $2
    fi
}

php-uses-namespace() {
    local r o=nocasematch; ! shopt -q $o || o=; ${o:+shopt -s $o}
    [[ $1 =~ ^((//|#).*$'\n'|'/*'.*'*/'|[[:space:]])*namespace[[:space:]]+[^\;{]*([;{]) ]]; r=$?
    ${o:+shopt -u $o}; REPLY=${BASH_REMATCH[3]-}; return $r
}

mdsh-compile-php() { compile-php imposer_php "$1"; }
```

### JSON and YAML

Because this script is installed via composer, there is a good chance that our current executable is alongside a `yaml2json.php` script.  If so, we set up jqmd's yaml2json PHP handler to use it:

```shell
if [[ $0 == "${BASH_SOURCE-}" ]]; then
    realpath.dirname "$0"
    realpath.absolute "$REPLY" yaml2json.php
    if [[ -x "$REPLY" ]]; then
        printf -v REPLY 'yaml2json:php() { %q; }' "$REPLY"; eval "$REPLY"
    fi
fi
```

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

#### State File Lookup

States are looked up in each directory on the imposer path, checking for files in the exact directory  or specific sub-locations thereof:

```shell
__find_state() {
    realpath.basename "$1"; local name=$REPLY
    realpath.dirname "$1"; local ns=$REPLY
    local patterns=("$1" "$1/default" "$1/imposer-states/default" "$ns/imposer-states/$name" )
    for REPLY in ${imposer_dirs[@]+"${imposer_dirs[@]}"}; do
        if reply_if_exists "$REPLY" "${patterns[@]/%/.state.md}"; then return; fi
    done
    false
}
```

#### State Loading

And then loaded by compiling the markdown source, optionally caching in the  `$IMPOSER_CACHE` directory (unless `IMPOSER_CACHE` is set to an empty string)

```shell
__load_state() {
    local IMPOSER_STATE=$1 bashup_event_after_state=   # just for this file
    if [[ ! "${IMPOSER_CACHE-_}" ]]; then
        run-markdown "$2"  # don't cache if IMPOSER_CACHE is an empty string
    else
        mdsh-cache "${IMPOSER_CACHE-$LOCO_ROOT/imposer/.cache}" "$2" "$1" unset -f mdsh:file-header mdsh:file-footer
        source "$REPLY"
    fi
    event fire "after_state"
    event emit "state_loaded" "$1" "$2"
}
```
### Processing JSON and PHP

After all required state files have been sourced, the accumulated YAML, JSON, and jq code they supplied is executed, to produce a JSON configuration.  All of the PHP code defined by this file and the state files is then run, with the JSON configuration as the `$state` variable.

```shell
imposer.apply() {
    require "$@"
    event fire "imposer_loaded"
    if HAVE_FILTERS; then
        declare -r IMPOSER_JSON="$(RUN_JQ -c -n)"
        event fire "json_loaded"
        cat-php imposer_php | wp eval-file - "$IMPOSER_JSON"
        event fire "imposer_done"
        CLEAR_FILTERS  # prevent auto-run to stdout
    fi
}
```

#### Dumping JSON or PHP

The `imposer json` and `imposer php` commands process state files and then output the resulting JSON or PHP without running the PHP.  (Any shell code in the states is still executed, however.)

```shell
imposer.json() { require "$@"; event fire "imposer_loaded"; ! HAVE_FILTERS || RUN_JQ -n; }
imposer.php()  { mdsh_raw_php=(); require "$@"; event fire "imposer_loaded"; CLEAR_FILTERS; cat-php imposer_php; }
```


