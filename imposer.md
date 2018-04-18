#!/usr/bin/env bash
: '
<!-- ex: set syntax=markdown : '; eval "$(jqmd -E "$BASH_SOURCE")"; # -->

# `imposer`: Impose States on a Wordpress Instance

Imposer is a modular configuration manager for Wordpress, allowing state information from outside the database (i.e. files, environment variables, etc.) to be "imposed" upon a Wordpress instance's database.  State information is defined in markdown files (like this one) containing mixed shell script, jq code, YAML, and PHP.

Imposer is built using [mdsh](https://github.com/bashup/mdsh), combining [loco](https://github.com/bashup/loco) for command-line parsing and configuration loading, and [jqmd](https://github.com/bashup/jqmd) for literate programming support.  It also uses [bashup/events](https://github.com/bashup/events) as its event subscription framework, and [.devkit](https://github.com/bashup/.devkit)'s `tty` module.

```shell mdsh
@module imposer.md
@main loco_main

@require pjeby/license @comment    "LICENSE"
@require bashup/jqmd   mdsh-source "$BASHER_PACKAGES_PATH/bashup/jqmd/jqmd.md"
@require bashup/loco   mdsh-source "$BASHER_PACKAGES_PATH/bashup/loco/loco.md"
@require bashup/events tail -n +2  "$BASHER_PACKAGES_PATH/bashup/events/bashup.events"
@require .devkit/tty   tail -n +2  "$DEVKIT_HOME/modules/tty"

echo tty_prefix=IMPOSER_   # Use IMPOSER_ISATTY, IMPOSER_PAGER, etc.
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

loco_site_config() { mark-read "$1"; run-markdown "$1"; }
loco_user_config() { mark-read "$1"; run-markdown "$1"; }
loco_loadproject() {
	cd "$LOCO_ROOT"
	if [[ $LOCO_PROJECT == *.md ]]; then
		@require "imposer:project" __load_state imposer-project "$LOCO_PROJECT"
	fi
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

wpcon() { wp "$@" --skip-plugins --skip-themes --skip-packages; }

get_imposer_dirs() {
    if [[ ${imposer_dirs[@]+_} ]]; then
        return
    elif (($#)); then
        true
    elif [[ ${IMPOSER_PATH-} ]]; then
        IFS=: eval 'set -- $IMPOSER_PATH'
    else
        set -- imposer \
            "${IMPOSER_THEMES=$(wpcon theme path)}" \
            "${IMPOSER_PLUGINS=$(wpcon plugin path)}" \
            "${IMPOSER_VENDOR=$( [[ -f composer.json ]] && composer config --absolute vendor-dir)}" \
            "${IMPOSER_PACKAGES=$(wpcon package path)}" \
            "${IMPOSER_GLOBALS=$(composer global config --absolute vendor-dir)}"
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

The following functions need to be available to both compile-time building of imposer, *and* at runtime, since both include PHP code:

```shell ! echo "$1"; eval "$1"
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

mdsh-compile-php() { compile-php imposer_php "$1" "$3"; }

# Dump one or more PHP buffers
cat-php() {
    local v1 v2 t php=()
    while (($#)); do
        v1=$1 v2=$1[1]; compact-php $v1 $v2; t=${!v1-}${!v2-}
        if php-uses-namespace "$t"; then
            compact-php php php[1] force
            php+="$t"
        else
            php[1]+="$t"
        fi
        shift
    done
    printf "<?php\n%s" "${php-}${php[1]-}"
}
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

The default state map begins with an empty options and plugins map (with imposer-tweaks disabled, to handle the case where one has removed all previous tweaks from an installation):

```yaml
options: {}
plugins: {imposer-tweaks: false}
```

which is then processed from PHP to modify wordpress options and plugins.

### Imposing Named States

States are imposed by sourcing the compiled form of their `.state.md` file, at most once.  States can require other states by calling `require` with one or more state names.

```shell
require() {
    get_imposer_dirs
    while (($#)); do @require "imposer-state:$1" __find_and_load "$1"; shift; done
}

__find_and_load() {
    if have_state "$1"; then
        __load_state "$1" "$REPLY"
    else loco_error "Could not find state $1 in ${imposer_dirs[*]}"
    fi
}
```

#### State File Lookup

`have_state` looks up states in each directory on the imposer path, checking for files in the exact directory  or specific sub-locations thereof.  Truth is returned if successful, along with the state's full filename in `$REPLY`.  Otherwise, false is returned.  Either way, the result is cached to speed up future lookups.

```shell
have_state() {
    event encode "$1"; local v="imposer_state_path_$REPLY"
    if [[ ${!v+_} ]]; then REPLY=${!v}; [[ $REPLY ]]; return; fi
    realpath.basename "$1"; local name=$REPLY
    realpath.dirname "$1"; local ns=$REPLY
    local patterns=("$1" "$1/default" "$1/imposer-states/default" "$ns/imposer-states/$name" )
    for REPLY in ${imposer_dirs[@]+"${imposer_dirs[@]}"}; do
        if reply_if_exists "$REPLY" "${patterns[@]/%/.state.md}"; then
            printf -v $v %s "$REPLY"; return
        fi
    done
    printf -v $v %s ""
    false
}
```

#### State Loading

And then loaded by compiling the markdown source, optionally caching in the  `$IMPOSER_CACHE` directory (unless `IMPOSER_CACHE` is set to an empty string)

```shell
__load_state() {
    local IMPOSER_STATE=$1 bashup_event_after__state=   # just for this file
    mark-read "$2"
    if [[ ! "${IMPOSER_CACHE-_}" ]]; then
        run-markdown "$2"  # don't cache if IMPOSER_CACHE is an empty string
    else
        mdsh-cache "${IMPOSER_CACHE-$LOCO_ROOT/imposer/.cache}" "$2" "$1"
        source "$REPLY"
    fi
    event fire "after_state"
    event emit "state_loaded" "$1" "$2"
}
```
#### State Tracking and File Listing

We track which state files are loaded, to allow for things like watching and re-running imposer.

```shell
files_used=()
mark-read() { files_used+=("$@"); }

run-states() { require "$@"; event fire "all_states_loaded"; }

files-read() {
	for REPLY in ${files_used[@]+"${files_used[@]}"}; do
	    realpath.relative "$REPLY"; echo "$REPLY"
	done
}

imposer.sources() {
	run-states "$@" >/dev/null; CLEAR_FILTERS
	tty pager -- files-read
}
```



### Processing JSON and PHP

After all required state files have been sourced, the accumulated YAML, JSON, and jq code they supplied is executed, to produce a JSON configuration.  All of the PHP code defined by this file and the state files is then run, with the JSON configuration as the `$state` variable.

```shell
imposer.apply() {
    run-states "$@"
    if HAVE_FILTERS; then
        CALL_JQ -c -n || return
        declare -r IMPOSER_JSON="$REPLY"
        event fire "before_apply"
        imposer_php[1]+=$'dirtsimple\Imposer::impose_json( $args[0] );\n'
        cat-php imposer_php | wp eval-file - "$IMPOSER_JSON"
        event fire "after_apply"
    fi
}
```

#### Dumping JSON or PHP

The `imposer json` and `imposer php` commands process state files and then output the resulting JSON or PHP without running the PHP.  (Any shell code in the states is still executed, however.)

```shell
imposer.json() { run-states "$@"; ! HAVE_FILTERS || RUN_JQ -n; }

colorize-php() { tty-tool IMPOSER_PHP_COLOR pygmentize -f 256 -O style=igor -l php; }

imposer.php()  {
    run-states "$@"; CLEAR_FILTERS
    tty pager colorize-php -- cat-php imposer_php
}
```

### Tweaks

Code blocks designated `php tweaks` are concatenated to create a modular, plugin-based alternative to managing patches in `functions.php`.

```php tweaks_header
# Plugin Name:  Imposer Tweaks
# Plugin URI:   https://github.com/dirtsimple.org/imposer#adding-code-tweaks
# Description:  Automatically-generated from tweaks in imposer state files
# Version:      0.0.0
# Author:       Various
# License:      Unknown

```

```shell
warn-unloaded-tweaks() {
    echo "warning: state $IMPOSER_STATE contains PHP tweaks that will not be loaded; tweaks must be defined in the project or global configuration." >&2
}

activate-tweaks() {
    php_tweaks=("" "${mdsh_raw_php_tweaks_header}")
    FILTER '.plugins."imposer-tweaks" = true'
    event off "php_tweak" activate-tweaks
    event on  "before_apply" write-plugin
}

write-plugin() {
    cat-php captured_tweaks >"${IMPOSER_PLUGINS=$(wpcon plugin path)}/imposer-tweaks.php"
}

capture-tweaks() {
    captured_tweaks=("${php_tweaks-}" "${php_tweaks[1]-}"); unset php_tweaks[@]
    event off "php_tweak" activate-tweaks
    event on  "php_tweak" event on "after_state" warn-unloaded-tweaks
}

event on "persistent_states_loaded" capture-tweaks
event on "php_tweak" activate-tweaks

mdsh-compile-php_tweak() { echo 'event emit php_tweak'; compile-php php_tweaks "$1" "$3"; }
```

