#!/bin/bash
# Quick build script for client-simulator (standalone)
set -e

ROOT="/www/azerothcore"
BUILD="$ROOT/build"
CXX="/usr/bin/clang++"

DEFINES="-DBOOST_ALL_NO_LIB -DBOOST_ASIO_NO_DEPRECATED -DBOOST_BIND_NO_PLACEHOLDERS"
DEFINES="$DEFINES -DBOOST_CHRONO_NO_LIB -DBOOST_CONFIG_SUPPRESS_OUTDATED_MESSAGE"
DEFINES="$DEFINES -DBOOST_DATE_TIME_NO_LIB -DBOOST_FILESYSTEM_DYN_LINK"
DEFINES="$DEFINES -DBOOST_PROGRAM_OPTIONS_DYN_LINK -DBOOST_REGEX_DYN_LINK -DBOOST_REGEX_NO_LIB"
DEFINES="$DEFINES -DBOOST_SERIALIZATION_NO_LIB -DBOOST_SYSTEM_DYN_LINK -DBOOST_SYSTEM_USE_UTF8"
DEFINES="$DEFINES -DENABLE_VMAP_CHECKS -DHAVE_SSE2 -DNO_BUFFERPOOL -DNO_CORE_FUNCS"
DEFINES="$DEFINES -DSFMT_MEXP=19937 -DFMT_CONSTEVAL="

CXXFLAGS="-O2 -g -DNDEBUG -std=gnu++20 -Wno-narrowing -Wno-deprecated-register"
CXXFLAGS="$CXXFLAGS -Wno-mismatched-tags -Woverloaded-virtual"

INCLUDES="-I$BUILD"
INCLUDES="$INCLUDES -I$ROOT/src/common -I$ROOT/src/common/Asio"
INCLUDES="$INCLUDES -I$ROOT/src/common/Collision -I$ROOT/src/common/Collision/Management"
INCLUDES="$INCLUDES -I$ROOT/src/common/Collision/Maps -I$ROOT/src/common/Collision/Models"
INCLUDES="$INCLUDES -I$ROOT/src/common/Configuration -I$ROOT/src/common/Cryptography"
INCLUDES="$INCLUDES -I$ROOT/src/common/Cryptography/Authentication -I$ROOT/src/common/DataStores"
INCLUDES="$INCLUDES -I$ROOT/src/common/Debugging -I$ROOT/src/common/Dynamic"
INCLUDES="$INCLUDES -I$ROOT/src/common/Dynamic/LinkedReference -I$ROOT/src/common/Encoding"
INCLUDES="$INCLUDES -I$ROOT/src/common/IPLocation -I$ROOT/src/common/Logging"
INCLUDES="$INCLUDES -I$ROOT/src/common/Metric -I$ROOT/src/common/Navigation"
INCLUDES="$INCLUDES -I$ROOT/src/common/Platform -I$ROOT/src/common/Threading"
INCLUDES="$INCLUDES -I$ROOT/src/common/Utilities -I$BUILD/src/common"
INCLUDES="$INCLUDES -I$ROOT/deps/argon2 -I$ROOT/deps/SFMT -I$ROOT/deps/utf8cpp"
INCLUDES="$INCLUDES -I$ROOT/deps/fmt/include -I$ROOT/deps/g3dlite/include"
INCLUDES="$INCLUDES -I$ROOT/deps/recastnavigation/Detour/Include"
INCLUDES="$INCLUDES -I$ROOT/modules/client-simulator"

SRCDIR="$ROOT/modules/client-simulator"
LIBS="$BUILD/src/common/libcommon.a -lssl -lcrypto -lz -lpthread"

mkdir -p "$BUILD/bin"

echo "=== Compiling client-simulator ==="
$CXX $CXXFLAGS $DEFINES $INCLUDES \
    -o "$BUILD/bin/client-simulator" \
    "$SRCDIR/main.cpp" \
    "$SRCDIR/AuthSocket.cpp" \
    "$SRCDIR/WorldSocket.cpp" \
    $LIBS

echo "=== Done: $BUILD/bin/client-simulator ==="
