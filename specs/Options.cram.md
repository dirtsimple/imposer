## The `imposer options` Command and `options-repo:` API

### Setup and Mocks

~~~sh
    $ source imposer; set +e  # don't exit on error
    $ umask 0022

    $ IMPOSER_CACHE=./cache
    $ IMPOSER_OPTIONS_SNAPSHOT=./repo
    $ IMPOSER_ISATTY=0

    $ imposer() { loco_do "$@"; }
    $ loco_preconfig

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

    $ edit-options() { writefile wp/options.json jq "$@" wp/options.json; }
    $ edit-options 'del(.baz)'
    $ cat wp/options.json
    {
      "foo": "bar"
    }

    $ ls -l wp/options.json
    -rw-r-----* wp/options.json (glob)
~~~

### options-repo:

The `setup` subcommand of `options-repo:` initializes a git repository in `$IMPOSER_OPTIONS_SNAPSHOT`, with the index containing the (filtered) current Wordpress options, in JSON form.  It chains any additional arguments as another `options-repo:` subcomand.

~~~sh
    $ options-repo: setup show json
    Initialized empty Git repository in */Options.cram.md/repo/.git/ (glob)
    {
      "foo": "bar"
    }

    $ cat repo/options.yml
    foo: bar
~~~

Initially, the index and `options.yml` file have the same contents, so `changed` is false, until a new `snapshot` is taken with different options :

~~~sh
    $ options-repo: changed && echo "changed" || echo "nope"
    nope

    $ edit-options -S '.bar="baz"'

    $ options-repo: snapshot changed && echo "changed" || echo "nope"
    changed
~~~

The `git` subcommand executes git commands in the options repo, so you can see the changes between the index (approved) and snapshot (current) options:

~~~sh
    $ options-repo: git --no-pager diff options.json
    diff --git a/options.json b/options.json
    index c8c4105..155271d 100644
    --- a/options.json
    +++ b/options.json
    @@ -1,3 +1,4 @@
     {
    +  "bar": "baz",
       "foo": "bar"
     }
~~~

The `freshen` subcommand updates the approved option state to reflect option changes made during the last `imposer apply`.  If there's no previous spec saved in `$IMPOSER_CACHE/last-applied.json`, it's a no-op.

The `approved-json` outputs the approved option state, updated to reflect option changes made during the last `imposer apply`.  If there's no previous spec saved in `$IMPOSER_CACHE/last-applied.json`, it's equivalent to `cat json`.  Using it in combination with the `edit` subcommand (which allows editing the approved options via a pipeline, it can be used to get a clean state for `changed` to test.

~~~sh
    $ options-repo: freshen changed && echo "changed" || echo "nope"
    changed

    $ writefile cache/last-applied.json yaml2json <<'EOF'
    > options:
    >   bar: baz
    > EOF

# Since the snapshot has the same change applied, there will no longer be a difference

    $ options-repo: freshen changed && echo "changed" || echo "nope"
    nope
~~~

### imposer options yaml

Outputs YAML for all parts of the current options that aren't currently approved or imposed.

~~~sh
# No change, no YAML:

    $ imposer options yaml
    null

# Change the options, see some YAML:

    $ edit-options -S '.bing={bang:"boom"}'
    $ imposer options yaml
    options: { bing: { bang: boom } }

# Impose the options, YAML goes away:

    $ edit-applied() { writefile cache/last-applied.json jq -S "$@" cache/last-applied.json; }
    $ edit-applied '.options.bing={bang:"boom"}'
    $ imposer options yaml
    null

~~~

### imposer options diff

~~~sh
# No change, no diff:

    $ imposer options diff

# Change the options, see some YAML:

    $ edit-options '.bing={bang:"pow!"}'
    $ imposer options diff
    diff --git a/options.yml b/options.yml
    index b9676fc..9c15c06 100644
    --- a/options.yml
    +++ b/options.yml
    @@ -1,3 +1,3 @@
     bar: baz
    -bing: { bang: boom }
    +bing: { bang: pow! }
     foo: bar

# Impose the options, no more diff:

    $ edit-applied '.options.bing={bang:"pow!"}'
    $ imposer options diff

~~~

### imposer options reset and review

~~~sh
# While diff suppresses imposed changes, the actual approved state hasn't changed

    $ options-repo: show json
    {
      "bar": "baz",
      "foo": "bar"
    }

# imposer options reset will approve all outstanding changes:

    $ imposer options reset
    $ options-repo: show json
    {
      "bar": "baz",
      "bing": {
        "bang": "pow!"
      },
      "foo": "bar"
    }

# Delete an option

    $ edit-options 'del(.bar)'
    $ edit-applied 'del(.options.bar)'

# But the change hasn't been approved, so it's still in the approved state:

    $ options-repo: show json
    {
      "bar": "baz",
      "bing": {
        "bang": "pow!"
      },
      "foo": "bar"
    }

# Until we review and approve it

    $ imposer options review <<'EOF'
    > a
    > EOF
    diff --git a/options.yml b/options.yml
    index 9c15c06..e3a5135 100644
    --- a/options.yml
    +++ b/options.yml
    @@ -1,3 +1,2 @@
    -bar: baz
     bing: { bang: pow! }
     foo: bar
    Stage this hunk [y,n,q,a,d,/,e,?]? 

# And now it's approved in JSON, so no more "bar" option:

    $ options-repo: show json
    {
      "bing": {
        "bang": "pow!"
      },
      "foo": "bar"
    }

    $ imposer options diff
~~~

### imposer options approve

~~~sh
# Add some stuff to an existing option:

    $ edit-options '.bing.whee = "whoa" | .bing.blah = "blah"'

# And it shows in the YAML

    $ imposer options yaml
    options: { bing: { whee: whoa, blah: blah } }

# Approve part of it

    $ imposer options approve bing.whee

# And it's gone from the YAML

    $ imposer options yaml
    options: { bing: { blah: blah } }

# Delete an option, approve it and the other part

    $ edit-options 'del(.foo)'
    $ imposer options approve foo bing.blah

# And the diffs are empty:

    $ imposer options diff
    $ imposer options yaml
    null
~~~