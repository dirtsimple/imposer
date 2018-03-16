# `imposer`: Impose States on a Wordpress Instance

Imposer is a modular configuration manager for Wordpress, allowing state information from outside the database (i.e. files, environment variables, etc.) to be *imposed* upon a Wordpress instance's database.  State information is defined in markdown files (like this one) containing mixed shell script, jq code, YAML, and PHP.

Imposer is built using [mdsh](https://github.com/bashup/mdsh), combining [loco](https://github.com/bashup/loco) for command-line parsing and configuration loading, and [jqmd](https://github.com/bashup/jqmd) for literate programming support.

```shell mdsh
@module imposer.md
@main loco_main

@import pjeby/license @comment    LICENSE
@import bashup/jqmd   mdsh-source "$BASHER_PACKAGES_PATH/bashup/jqmd/jqmd.md"
@import bashup/loco   mdsh-source "$BASHER_PACKAGES_PATH/bashup/loco/loco.md"
```



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

### State Directories

State files are searched for in `IMPOSER_PATH` -- a `:`-separated string of directory names.  If no `IMPOSER_PATH` is set, one is generated that consists of:

* The Wordpress plugin directory (e.g. `plugins/`)
* The `COMPOSER_VENDOR_DIR` (or `vendor` if not specified)
* The wp-cli package path (typically `~/.wp-cli/packages`)
* The global composer `vendor` directory, i.e. `${COMPOSER_HOME}/vendor`.

```shell
imposer_dirs=()

get_imposer_dirs() {
    if (($#)); then
       true
    elif [[ $IMPOSER_PATH ]]; then
        IFS=: eval 'set -- $IMPOSER_PATH'
    else
        if [[ $XDG_CONFIG_HOME ]]; then
            local pre=${XDG_CONFIG_HOME}/
        else local pre=${HOME}/.
        fi
        set -- imposer "$(wp plugin path)" "${COMPOSER_VENDOR_DIR-vendor}" \
               "$(wp package path)" "${COMPOSER_HOME-${pre}composer}/vendor";
    fi
    imposer_dirs=()
    for REPLY; do
        if [[ "$REPLY" && -d "$REPLY"]]; then
            realpath.absolute "$REPLY"
            imposer_dirs+=("${REPLY%/}/")
        fi
    done
}
```



## State Handling

### JSON and YAML

YAML and JSON blocks in state files are processed using a doco-style recursive add:

```jq defs
def jqmd_data($data): . as $orig |
    reduce paths(type=="array") as $path (
        (. // {}) * $data; setpath( $path; ($orig | getpath($path)) + ($data | getpath($path)) )
    );
```

The default state map begins with an empty options map:

```yaml
options: {}
```

which is then processed from PHP to modify wordpress options:

```php
$state = json_decode($args[0], true);
$options = empty($state['options']) ? [] : $state['options'];

foreach ($options as $opt => $new) {
    $old = get_option($opt);
    if (is_array($old) && is_array($new)) $new = array_replace_recursive($old, $new);
    if ($new !== $old) {
        if ($old === false) add_option($opt, $new); else update_option($opt, $new);
    }
}
```

### Imposing Named States

States are imposed by sourcing the compiled form of their `.state.md` file, at most once.  States can require other states by calling `require` with one or more state names.

```shell
imposed_states=
require() {
    [[ ${imposer_dirs[@]+_} ]] || get_imposer_dirs;
    while (($#)); do
        [[ $imposed_states == *"<$1>"* ]] || {
            imposed_states+="<$1>"
            load-state "$1"
        }
        shift
    done
}

load-state() {
    realpath.basename "$1"; local base=$REPLY
    local patterns=("$1" "$1/$base" "$1/default" "$1/imposer/$base" "$1/imposer/default" )
    for REPLY in ${imposer_dirs[@]+_"${imposer_dirs[@]}"}; do
        if reply_if_exists "$REPLY" "${imposer_patterns[@]/%/.state.md}"; then
            run-markdown "$REPLY"  # XXX cache compiled version here?
            return
        fi
    done
    loco_error "Could not find state $1 in ${imposer_dirs[*]}"
}
```
### Processing JSON and PHP

After all required state files have been sourced, the accumulated YAML, JSON, and jq code they supplied is executed, to produce a JSON configuration.  All of the PHP code defined by this file and the state files is then run, with the JSON configuration as the `$state` variable.

```shell
imposer.require() {
    require "$@"
    if HAVE_FILTERS; then
        REPLY=$(RUN_JQ -c -n)
        CLEAR_FILTERS  # prevent auto-run to stdout
        printf '%s\n' '<?php' "${mdsh_raw_php[@]}" | wp eval-file - "$REPLY"
    fi
}
```