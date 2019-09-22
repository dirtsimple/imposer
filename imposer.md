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
@require bashup/dotenv mdsh-source "$BASHER_PACKAGES_PATH/bashup/dotenv/dotenv.md"
@require bashup/events tail -n +2  "$BASHER_PACKAGES_PATH/bashup/events/bashup.events"
@require .devkit/tty   tail -n +2  "$DEVKIT_HOME/modules/tty"

echo tty_prefix=IMPOSER_   # Use IMPOSER_ISATTY, IMPOSER_PAGER, etc.
```

## Core Configuration

### File and Function Names

Configuration is loaded using loco.  Subcommand functions are named `imposer.X`, where `X` is the command or option name.

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
	# load environment variables
	.env -f .imposer-env export
	IMPOSER_CACHE=${IMPOSER_CACHE:-$LOCO_ROOT/imposer/.cache}
	if [[ $LOCO_PROJECT == *.md ]]; then
		@require "imposer:project" __load_module imposer-project "$LOCO_PROJECT"
	fi
	event resolve persistent_modules_loaded
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
    if [[ ${imposer_dirs+_} ]]; then
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
            "${IMPOSER_PACKAGES=$(wpcon package path)/vendor}" \
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
    if [[ ${imposer_dirs+_} ]]; then IFS=: eval 'echo "${imposer_dirs[*]}"'; fi
}

imposer.default-path() { local imposer_dirs=() IMPOSER_PATH=; imposer path; }
```

## State Handling

### PHP Parsing and Output

PHP blocks are syntax-checked at compile time (so that the check is cached).  PHP blocks can be assembled in any array variable; element 0 contains namespaced code, and element 1 contains non-namespaced code that hasn't yet been wrapped in a namespace.  Namespace wrapping is lazy: as many non-namespaced blocks as possible are wrapped in a single large `namespace { }` block, instead of wrapping each block as it comes.

```shell
compile-php() {
    if REPLY="$(echo "<?php $2" | php -l 2>&1)"; local s=$?; ((s)); then
        php-error "$REPLY" $s "${3-}"; return
    elif php-uses-namespace "$2"; then
        if [[ $REPLY != '{' ]]; then
            php-error "Namespaces in PHP blocks must be {}-enclosed" 255 "${3-}"; return
        fi
        printf 'compact-php %s %s[1] force\n' "$1" "$1"
    else
        printf 'maybe-compact-php %s %s[1] %s[2]\n' "$1" "$1" "$1"
        set -- "$1[1]" "$2"
    fi
    printf '%s+=%q\n' "$1" "$2"
}

php-error() {
    echo "In PHP block ${3:+at line ${3-} }of ${MDSH_SOURCE--}:"$'\n'"$1" >&2; return "$2"
}

maybe-compact-php() {
    if [[ "${!3-}" && "${!3-}" != "${__FILE__-}" ]]; then
        compact-php "$1" "$2" force
    fi
    eval "$3=\${__FILE__-}"
}

compact-php() {
    if [[ "${!1-}${3-}" && "${!2-}" ]]; then
        printf -v "$1" '%snamespace {\n%s}\n' "${!1-}" "${!2}"; unset "$2"
    fi
}

php-uses-namespace() {
    local r o=nocasematch; ! shopt -q $o || o=; ${o:+shopt -s $o}
    [[ $1 =~ ^((//|#).*$'\n'|'/*'.*'*/'|[[:space:]])*namespace[[:space:]]+[^\;{]*([;{]) ]]; r=$? #))
    ${o:+shopt -u $o}; REPLY=${BASH_REMATCH[3]-}; return $r
}

mdsh-compile-php() { compile-php imposer_php "$1" "$3"; }

# Dump one or more PHP buffers
cat-php() {
    local v1 v2 t php=()
    while (($#)); do
        v1=$1 v2="$1[1]"; compact-php "$v1" "$v2"; t=${!v1-}${!v2-}
        if php-uses-namespace "$t"; then
            compact-php php php[1] force
            php[0]+="$t"
        else
            php[1]+="$t"
        fi
        shift
    done
    [[ ! "${php-}${php[1]-}" ]] || printf '<?php\n%s' "${php-}${php[1]-}"
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

The default specification begins with an empty options and plugins map (with imposer-tweaks disabled, to handle the case where one has removed all previous tweaks from an installation):

```yaml
options: {}
plugins: {imposer-tweaks: false}
```

which is then processed from PHP to modify wordpress options and plugins.

To make environment variable interpolation easier for YAML blocks, a `_` function is defined in jq that returns a null string:

```jq defs
def _: "";
```

### Imposing State Modules

State modules are imposed by sourcing the compiled form of their `.state.md` file, at most once.  State modules can require other modules by calling `require` with one or more module names.

```shell
require() {
    get_imposer_dirs
    while (($#)); do @require "imposer-module:$1" __find_and_load "$1"; shift; done
}

__find_and_load() {
    if have_module "$1"; then
        __load_module "$1" "$REPLY"
    else loco_error "Could not find module $1 in ${imposer_dirs[*]}"
    fi
}
```

#### State File Lookup

`have_module` looks up state modules in each directory on the imposer path, checking for files in the exact directory  or specific sub-locations thereof.  Truth is returned if successful, along with the module's full filename in `$REPLY`.  Otherwise, false is returned.  Either way, the result is cached to speed up future lookups.

```shell
have_module() {
    event encode "$1"; local v="imposer_module_path_$REPLY"
    if [[ ${!v+_} ]]; then REPLY=${!v}; [[ $REPLY ]]; return; fi
    realpath.basename "$1"; local name=$REPLY
    realpath.dirname "$1"; local ns=$REPLY
    local patterns=("$1" "$1/default" "$1/imposer-states/default" "$ns/imposer-states/$name" )
    for REPLY in ${imposer_dirs[@]+"${imposer_dirs[@]}"}; do
        if reply_if_exists "$REPLY" "${patterns[@]/%/.state.md}"; then
            printf -v "$v" %s "$REPLY"; return
        fi
    done
    printf -v "$v" %s ""
    false
}
```

#### State Loading

And then loaded by compiling the markdown source, caching in the  `$IMPOSER_CACHE` directory

```shell
__load_module() {
    realpath.dirname "$2"
    # shellcheck disable=SC2034  # vars for users + event var
    local __FILE__="$2" __DIR__=$REPLY IMPOSER_MODULE="$1" bashup_event_after_5fmodule=
    mark-read "$2"
    MDSH_CACHE=${IMPOSER_CACHE} mdsh-run "$2" "$1"
    # Force error exit if module fails to compile or run
    mdsh-ok || exit "$?" "Module $1 ($2) aborted with code $?"
    event fire "after_module"
    event emit "module_loaded" "$1" "$2"
    event resolve "module_loaded_$1" "$2"
}
```
#### State Tracking and File Listing

We track which state files are loaded, to allow for things like watching and re-running imposer.

```shell
files_used=()
mark-read() { files_used+=("$@"); }

run-modules() {
    # Only require up to the first '--' option
    while (($#)) && [[ "$1" != --* ]]; do require "$1"; shift; done
    event fire "all_modules_loaded"
}

files-read() {
	for REPLY in ${files_used[@]+"${files_used[@]}"}; do
	    realpath.relative "$REPLY"; echo "$REPLY"
	done
}

imposer.sources() {
	run-modules "$@" >/dev/null; CLEAR_FILTERS
	tty pager -- files-read
}
```



### Processing JSON and PHP

After all required state files have been sourced, the accumulated YAML, JSON, and jq code they supplied is executed, to produce a JSON specification.  All of the PHP code defined by the state modules are then run, with the JSON configuration piped in.

If the PHP process exits with error 75 (EX_TEMPFAIL), it is re-run, as that means a change was made to the set of active plugins, or critical settings such as the current theme.

```shell
imposer.apply() {
    run-modules "$@"
    if HAVE_FILTERS; then
        CALL_JQ -c -n || return
        declare -r IMPOSER_JSON="$REPLY"
        event fire "before_apply"
        while local s=; run-imposer-php "$@" || s=$?; [[ $s == 75 ]]; do :; done
        ${s:+return $s}
        writefile "$IMPOSER_CACHE/last-applied.json" echo "$IMPOSER_JSON"
        event fire "after_apply"
    fi
}

run-imposer-php() {
    # skip non-option arguments
    while (($#)) && [[ "$1" != --* ]]; do shift; done
    wp eval 'dirtsimple\imposer\Imposer::run_stream("php://fd/7");' "$@" \
        7<<<"$IMPOSER_JSON" < <(cat-php imposer_php)
}

writefile() {
	realpath.dirname "$1"; mkdir -p "$REPLY" || return
	[[ ! -f "$1" ]] || cp -p "$1" "$1.tmp" || return
	"${@:2}" >"$1.tmp" && mv "$1.tmp" "$1"
}

```

#### Dumping JSON or PHP

The `imposer json` and `imposer php` commands process state modules and then output the resulting JSON or PHP without running the PHP.  (Any shell code in the modules is still executed, however.)

```shell
imposer.json() { run-modules "$@"; ! HAVE_FILTERS || JQ_CMD=jq-tty RUN_JQ -n; }

imposer.php()  {
    run-modules "$@"; dump-php imposer_php
}

dump-php() {
    CLEAR_FILTERS
    tty pager colorize-php -- cat-php "$@"
}
```

### Tweaks

Code blocks designated `php tweaks` are concatenated to create a modular, plugin-based alternative to managing patches in `functions.php`.  This block of PHP is used as a header for the plugin file:

```php tweaks_header
# Plugin Name:  Imposer Tweaks
# Plugin URI:   https://github.com/dirtsimple/imposer#adding-code-tweaks
# Description:  Automatically-generated from tweaks in imposer state modules
# Version:      0.0.0
# Author:       Various
# License:      Unknown

```

And this block is used as a footer, in the event any `php cli` blocks are used.  (Such blocks are similarly concatenated to create an auxiliary include file, that's only run for WP-CLI commands.)

```php cli_footer
if ( defined('WP_CLI') && WP_CLI ) (function($file){
    if (is_file($file)) require_once $file;
})(__DIR__.'/imposer-tweaks.cli.php');
```

```shell
warn-unloaded-tweaks() {
    echo "warning: module '$IMPOSER_MODULE' contains PHP tweaks that will not be loaded; tweaks must be defined in the project or global configuration." >&2
}

activate-tweaks() {
    php_tweaks=("" "${mdsh_raw_php_tweaks_header}")
    FILTER '.plugins."imposer-tweaks" = true'
    event off "php_tweak" activate-tweaks
    event on  "before_apply" write-plugin
}

write-plugin() {
    mkdir -p "${IMPOSER_PLUGINS=$(wpcon plugin path)}"
    local tweaks="$IMPOSER_PLUGINS/imposer-tweaks"
    cat-php captured_cli    >"$tweaks.cli.tmp"
    replace-file-if-changed  "$tweaks.cli.tmp" "$tweaks.cli.php"
    cat-php captured_tweaks >"$tweaks.tmp"
    replace-file-if-changed  "$tweaks.tmp" "$tweaks.php"
}

replace-file-if-changed(){
    if [[ ! -s "$1" ]]; then
        rm "$1"   # zero length file; remove it and the target
        [[ ! -f "$2" ]] || rm "$2"
    elif [[ -f "$2" ]] && diff -q "$1" "$2" >/dev/null; then
        rm "$1"   # no content changed, don't overwrite
    else
        mv "$1" "$2"
    fi
}

# shellcheck disable=SC2034  # captured_tweaks & cli are used in write-plugin function above
capture-tweaks() {
    if [[ "${php_cli-}${php_cli[1]-}" ]]; then
        captured_cli=("${php_cli-}" "${php_cli[1]-}"); unset "php_cli[@]"
        php_tweaks[1]+=$'\n'${mdsh_raw_php_cli_footer}
    fi
    captured_tweaks=("${php_tweaks-}" "${php_tweaks[1]-}"); unset "php_tweaks[@]"
    event off "php_tweak" activate-tweaks
    event on  "php_tweak" event on "after_module" warn-unloaded-tweaks
}

event on "persistent_modules_loaded" capture-tweaks
event on "php_tweak" activate-tweaks

mdsh-compile-php_tweak() { echo 'event emit php_tweak'; compile-php php_tweaks "$1" "$3"; }
mdsh-compile-php_cli()   { echo 'event emit php_tweak'; compile-php php_cli    "$1" "$3"; }

imposer.tweaks()  {
    if (($#)); then
        loco_subcommand "imposer.tweaks-" "imposer.tweaks---help" "$@"
    else
        run-modules; dump-php captured_tweaks
    fi
}

imposer.tweaks---help() {
	loco_subcommand_help "imposer.tweaks-" "imposer tweaks [command [args...]]" \
	'Output the PHP that would be generated by `imposer apply`' ""
}

imposer.tweaks-cli(){
    if (($#)); then echo $'`imposer tweaks cli` does not accept arguments' >&2; exit 64; fi
    run-modules; dump-php captured_cli
}

```

### Block Events

When a block of an unrecognized language is found, it's compiled to emit an event, `block of X`, where `X` is the full original language tag of the block.  The event receives four arguments: the block text, the module name, source file name, and line number where the block was found.

```shell
mdsh-misc(){
	printf 'event emit "block of "%q %q %q %q %q\n' "$1" "$2" \
		"$IMPOSER_MODULE" "$MDSH_SOURCE" "${block_start-}"
}
```

## Options Monitoring

### Option Filtering

Many Wordpress options are not really "options", but scratch storage for plugins.  These options add useless noise to the option monitoring tools and may need to be filtered out.  So we add `filter-options` and `exclude-options` to generate jq code to filter them during list, diff, etc.

```shell
exclude-options() { printf -v REPLY '.%s, ' "$@"; filter-options "del(${REPLY%, })"; }
filter-options()  { event on "filter options" FILTER "$1"; }

exclude-options cron recently_activated

imposer-filtered-options() {
	wp option list --unserialize --format=json --no-transients --orderby=option_name "$@" |
	(
		CLEAR_FILTERS; FILTER 'map({key:.option_name, value:.option_value}) | from_entries'
		event emit "filter options"; eval 'JQ_CMD=(jq-tty)'; RUN_JQ
	)
}
```

### The Options Repository

`options-repo:` is a singleton object with methods to run git commands in the current repo, calculate the repo dir, initialize it, take snapshots, etc.

```shell
options-repo:() { (($#==0)) || "options-repo::$@"; }

options-repo::has-directory() {
	# Default to .options-snapshot under IMPOSER_CACHE
	REPLY=$IMPOSER_CACHE/.options-snapshot; [[ ${IMPOSER_OPTIONS_SNAPSHOT-} ]]
}

options-repo::git() ( cd "$IMPOSER_OPTIONS_SNAPSHOT" && git "$@"; )
options-repo::changed() { [[ "$(options-repo: git status --porcelain options.json)" == ?M* ]]; }
options-repo::snapshot(){ options-repo: write json imposer-filtered-options && options-repo: "$@"; }
options-repo::write(){ writefile "$IMPOSER_OPTIONS_SNAPSHOT/options.$1" "${@:2}"; }
options-repo::show() { options-repo: git show :options."$1"; }
options-repo::add()  { options-repo: write "$@" && options-repo: git add options."$1"; }
options-repo::edit() { options-repo: show json | options-repo: add json "$@"; }

options-repo::freshen() {
	# Update git index with last options applied by imposer, if present
	options-repo: setup || return
	[[ ! -f "$IMPOSER_CACHE/last-applied.json" ]] ||
		options-repo: add json options-repo: approved-json || return
	options-repo: snapshot "$@"
}

options-repo::approved-json() {
	REPLY=$IMPOSER_CACHE/last-applied.json
	if [[ -f "$REPLY" ]]; then
		options-repo: show json | jq --slurpfile last "$REPLY" '
			delpaths( [ $last[0]."delete-options" | paths(type != "object") ]) |
			. * $last[0].options | to_entries | sort_by(.key) | from_entries
		'
	else options-repo: show json
	fi
}

options-repo::to-json() {
	options-repo: show yml | yaml2json.php | options-repo: add json jq . &&
	options-repo: snapshot "$@"
}

options-repo::to-yaml() {
	options-repo: approved-json | options-repo: add   yml json2yaml.php &&
	imposer-filtered-options    | options-repo: write yml json2yaml.php &&
	options-repo: "$@"
}

options-repo::setup() {
	[[ ${IMPOSER_OPTIONS_SNAPSHOT-} ]] || loco_error "A snapshot directory name is required"
	[[ -d "$IMPOSER_OPTIONS_SNAPSHOT" ]] || mkdir -p "$IMPOSER_OPTIONS_SNAPSHOT"
	[[ -d "$IMPOSER_OPTIONS_SNAPSHOT/.git" ]] || options-repo: git init

	local options=$IMPOSER_OPTIONS_SNAPSHOT/options
	[[ -f "$options.yml" ]] || {
		[[ -f "$options.json" ]] || options-repo: add json imposer-filtered-options
		options-repo: to-yaml
	}
	[[ -f "$options.json" ]] || options-repo: to-json
	options-repo: "$@";
}
```

### imposer options

The `imposer options` command runs subcommands, calculating the default repo path if needed.  The `--dir` option is implemented as a subcommand that overrides the current repo path.

```shell
imposer.options() {
	options-repo: has-directory || local IMPOSER_OPTIONS_SNAPSHOT=$REPLY
	loco_subcommand "imposer.options-" "imposer.options---help" "$@"
}

imposer.options---help() {
	loco_subcommand_help "imposer.options-" "imposer options [--dir SNAPSHOT-DIR]"
}

imposer.options-jq() { imposer-filtered-options | jq-tty "$@"; }

imposer.options---dir() {
	(($#>1)) || imposer options --help
	local IMPOSER_OPTIONS_SNAPSHOT=$1
	imposer options "${@:2}"
}

# These bits should get promoted upstream
loco_subcommand() { if fn-exists "${1}${3-}"; then "${1}${@:3}"; else "${@:2}"; fi; }

loco_subcommand_help() {
	REPLY=$(compgen -A function "$1") && mdsh-splitwords "$REPLY"
	printf -v REPLY '\n    %s' "${REPLY[@]#$1}"
	printf -v REPLY "Usage: %s${4- COMMMAND [ARGS...]}\\n\\n%s${3:+\\n\\n}Commands:\\n%s" \
		"$2" "${3-}" "$REPLY"
	loco_error "$REPLY"
}
```

#### list, diff, review, reset

`imposer options list` dumps all options in JSON form (w/paging and colorizing if output goes to a TTY.  Any extra arguments are passed on to `wp option list`.  `imposer options diff` diffs the current options against the last save in YAML format (again with paging and colorizing if possible).  `imposer options review`  waits for changes and then runs `git add --patch` on them.

```shell
imposer.options-list() { imposer-filtered-options "$@"; }

imposer.options-diff() {
	tty pager diffcolor -- options-repo: setup to-yaml git --no-pager diff options.yml
}

imposer.options-review() {
	(($#==0)) || loco_error "Usage: imposer options [--dir SNAPSHOT-DIR] review"
	while ! options-repo: freshen changed; do
		(($#)) || { echo "Waiting for changes...  (^C to abort)"; set -- started; }
		sleep 10
	done
	options-repo: to-yaml git add --patch options.yml
	options-repo: to-json
}

imposer.options-reset() {
	(($#==0)) || loco_error "Usage: imposer options [--dir SNAPSHOT-DIR] reset"
	options-repo: setup add json options-repo: approved-json
}
```

#### yaml

`imposer options yaml` attempts to write the minimal YAML that needs to be added to the current specification to match the current option state, after standard option filtering and excluding already-reviewed option values.  The resulting YAML is paged and colorized.  (An important limitation: the generated YAML cannot *delete* any options or option subkeys that were deleted in the actual options.)

```shell
imposer.options-yaml() { tty pager colorize-yaml -- unspecified-new-options; }

@provide imposer::rdiff DEFINE '
	def imposer::rdiff($old):
	  if . == $old then empty
	  elif type=="object" and ($old|type)=="object" then
	    . as $new | with_entries(
	      .key as $k |
	      if $k | in($old) then .value | imposer::rdiff($old[$k]) | {key:$k, value:.} else . end
	    ) | if . == {} and $new != {} then empty else . end
	  else .
	  end;
'

# shellcheck disable=SC2154
unspecified-new-options() {
	@require imposer::rdiff
	IMPOSER_ISATTY=0 imposer-filtered-options |
	jq --slurpfile old_options <(options-repo: setup approved-json) "$jqmd_defines
		{ options: . } | imposer::rdiff( { options: \$old_options[0]} )" | json2yaml.php
}
```

#### watch

`imposer options watch` runs  `imposer options diff --no-pager` every 15 seconds, with colorized output cut to fit the current screen size.  OS X doesn't have a native `watch` command, so we emulate it, adding support for terminal resizing by trapping SIGWINCH.

```shell
imposer.options-watch() {
	(($#==0)) || loco_error "Usage: imposer options [--dir SNAPSHOT-DIR] watch"
	watch-continuous 10 imposer options diff "$@"
}

watch-continuous() {
	local interval=$1 oldint oldwinch; oldint=$(trap -p SIGINT); oldwinch="$(trap -p SIGWINCH)"
	shift; trap "continue" SIGWINCH; trap "break" SIGINT
	while :; do watch-once "$@"; sleep "$interval" & wait $! || true; done
	${oldwinch:-trap -- SIGWINCH}; ${oldint:-trap -- SIGINT}
}

watch-once() {
	local cols; cols=$(tput cols) 2>/dev/null || cols=80
	REPLY="Every ${interval}s: $*"
	clear; printf '%s%*s\n\n' "$REPLY" $((cols-${#REPLY})) "$(date "+%Y-%m-%d %H:%M:%S")"
	IMPOSER_PAGER="pager.screenfull 3" "$@" || true
}
```

## Other Commands

### imposer env

Wrap `.env -f .imposer-env ...` as an imposer command, to allow setting env vars from the command line that will be used by the current project.

```shell
imposer.env() { loco_subcommand "imposer.env-" "imposer-env" "$@"; }
imposer-env() {
	{ (($#)) && declare -F -- ".env.$1" >/dev/null; } || { imposer env --help; return; }
	.env -f .imposer-env "$@"; ${REPLY[@]+printf '%s\n' "${REPLY[@]}"}
}
imposer.env---help() { loco_subcommand_help ".env." "imposer env"; }
imposer.env-export() {
	.env -f .imposer-env; .env parse "$@" || return 0; printf 'export %q\n' "${REPLY[@]}"; REPLY=()
}
```