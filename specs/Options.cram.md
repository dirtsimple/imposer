## The `imposer options` Command and `options-repo:` API

### Setup and Mocks

~~~sh
    $ source imposer; set +e  # don't exit on error
    $ umask 0022

    $ IMPOSER_CACHE=./cache
    $ IMPOSER_OPTIONS_SNAPSHOT=./repo
    $ IMPOSER_ISATTY=0

    # Mock filtered "wp option list" using file contents
    $ imposer-filtered-options() { cat wp/options.json; }
~~~

### writefile

`writefile` *file command...* saves the output of *command...* to a temporary file and then moves it to atomically replace *file* upon success. Any missing parent directories of *file* are created first, and permissions are preserved if the file exists.  Because it's a temporary file, it's safe to edit a .json file "in-place" with jq:

~~~sh
    $ writefile wp/options.json yaml2json.php <<'EOF'
    > foo: bar
    > baz: spam
    > EOF

    $ jq -c . wp/options.json
    {"foo":"bar","baz":"spam"}

    $ ls -l wp/options.json
    -rw-r--r--* wp/options.json (glob)

    $ chmod 640 wp/options.json
    $ ls -l wp/options.json
    -rw-r-----* wp/options.json (glob)

    $ edit-options() { writefile wp/options.json jq -S -c "$@" wp/options.json; }
    $ edit-options 'del(.baz)'
    $ cat wp/options.json
    {"foo":"bar"}

    $ ls -l wp/options.json
    -rw-r-----* wp/options.json (glob)
~~~

### options-repo:

The `setup` subcommand of `options-repo:` initializes a git repository in `$IMPOSER_OPTIONS_SNAPSHOT`, with the index containing the (filtered) current Wordpress options, in JSON form.  It chains any additional arguments as another `options-repo:` subcomand.

~~~sh
    $ options-repo: setup cat-json
    Initialized empty Git repository in */Options.cram.md/repo/.git/ (glob)
    {"foo":"bar"} (no-eol)

    $ cat repo/options.yml
    foo: bar
~~~

Initially, the index and `options.yml` file have the same contents, so `changed` is false, until a new `snapshot` is taken with different options :

~~~sh
    $ options-repo: changed && echo "changed" || echo "nope"
    nope

    $ edit-options '.bar="baz"'

    $ options-repo: snapshot changed && echo "changed" || echo "nope"
    changed
~~~

The `git` subcommand executes git commands in the options repo, so you can see the changes between the index (approved) and snapshot (current) options:

~~~sh
    $ options-repo: git --no-pager diff options.yml
    diff --git a/options.yml b/options.yml
    index 20e9ff3..a0e0810 100644
    --- a/options.yml
    +++ b/options.yml
    @@ -1 +1,2 @@
    +bar: baz
     foo: bar
~~~

The `freshen` subcommand updates the approved option state to reflect option changes made during the last `imposer apply`.  If there's no previous spec saved in `$IMPOSER_CACHE/last-applied.json`, it's a no-op.

~~~sh
    $ options-repo: freshen snapshot changed && echo "changed" || echo "nope"
    changed

    $ writefile cache/last-applied.json yaml2json <<'EOF'
    > options:
    >   bar: baz
    > EOF

# Since the snapshot has the same change applied, there will no longer be a difference

    $ options-repo: freshen snapshot changed && echo "changed" || echo "nope"
    nope
~~~

### imposer options yaml

Outputs YAML for all parts of the current options that aren't currently approved or imposed.

~~~sh
# No change, no YAML:

    $ imposer.options-yaml
    null

# Change the options, see some YAML:

    $ edit-options '.bing={bang:"boom"}'
    $ imposer.options-yaml
    options: { bing: { bang: boom } }

# Impose the options, YAML goes away:

    $ writefile cache/last-applied.json jq -Sc '.options.bing={bang:"boom"}' cache/last-applied.json
    $ imposer.options-yaml
    null

~~~