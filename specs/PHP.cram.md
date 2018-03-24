## PHP Parsing and "Compiling"

````sh
# Load functions and turn off error exit
    $ source "$TESTDIR/../imposer.md"; set +e
````

### php-uses-namespace

````sh
# Check if a block of code uses PHP namespaces

    $ php-uses-namespace 'namespace foo;'; echo "$?,$REPLY"
    0,;
    $ php-uses-namespace 'namespace { }'; echo "$?,$REPLY"
    0,{
    $ php-uses-namespace '// namespace { }'; echo "$?,$REPLY"
    1,
    $ php-uses-namespace '/* */ namespace x;'; echo "$?,$REPLY"
    0,;
    $ php-uses-namespace $'// xx \n namespace xy\\z {'; echo "$?,$REPLY"
    0,{
    $ php-uses-namespace $'# xx \n namespace xy\\z;'; echo "$?,$REPLY"
    0,;
````

### compile-php and cat-php

````sh
# compile-php writes code to save PHP under a given variable name:

    $ compile-php myvar $'// some code\n'
    myvar[1]+=$'// some code\n'

    $ compile-php myvar $'namespace foo { }\n'
    compact-php myvar myvar[1] force
    myvar+=$'namespace foo { }\n'

# That code is then dumpable via cat-php:

    $ eval "$(compile-php myvar $'// some code\n')"
    $ cat-php myvar
    <?php
    // some code

# Blocks are syntax checked before addition, returning 255 on error:

    $ (MDSH_SOURCE=$TESTFILE compile-php myvar "if this(that)" 42)
    In PHP block at line 42 of PHP.cram.md:
    PHP Parse error:  syntax error, unexpected 'this' (T_STRING), expecting '(' in - on line 1
    Errors parsing -
    [255]

# Only `{}`-based namespaces are allowed:

    $ (MDSH_SOURCE=$TESTFILE compile-php myvar "namespace foo;" 21)
    In PHP block at line 21 of PHP.cram.md:
    Namespaces in PHP blocks must be {}-enclosed
    [255]

# Non-namespaced blocks concatenate:

    $ eval "$(compile-php myvar $'/* more code */\n')"
    $ cat-php myvar
    <?php
    // some code
    /* more code */

# Namespaced blocks wrap non-namespaces:

    $ eval "$(compile-php myvar $'namespace foo { }\n')"
    $ cat-php myvar
    <?php
    namespace {
    // some code
    /* more code */
    }
    namespace foo { }

# And subsequent blocks get lazily namespaced:

    $ eval "$(compile-php myvar $'# even more code\n')"
    $ eval "$(compile-php myvar $'# still more code\n')"
    $ cat-php myvar
    <?php
    namespace {
    // some code
    /* more code */
    }
    namespace foo { }
    namespace {
    # even more code
    # still more code
    }

````

