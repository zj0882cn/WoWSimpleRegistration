# client-simulator: 独立 WoW 客户端模拟程序
# 通过 TCP 直接连接 authserver/worldserver，完全模拟真实客户端行为
# 不依赖 worldserver 内部 API，仅链接 common 库

set(SIMULATOR_SOURCES
    main.cpp
    AuthSocket.cpp
    WorldSocket.cpp
)

find_package(Threads REQUIRED)

add_executable(client-simulator ${SIMULATOR_SOURCES})

find_package(ZLIB REQUIRED)

target_link_libraries(client-simulator
    PRIVATE
        common
        Threads::Threads
        ZLIB::ZLIB
)

target_include_directories(client-simulator
    PRIVATE
        ${CMAKE_CURRENT_SOURCE_DIR}
)

set_target_properties(client-simulator
    PROPERTIES
        FOLDER "tools"
        RUNTIME_OUTPUT_DIRECTORY "${CMAKE_BINARY_DIR}/bin")

# SRP6 debug test
add_executable(client_srp6_test srp6_debug_test.cpp)
target_link_libraries(client_srp6_test PRIVATE common Threads::Threads)
target_include_directories(client_srp6_test PRIVATE ${CMAKE_CURRENT_SOURCE_DIR})
set_target_properties(client_srp6_test
    PROPERTIES
        FOLDER "tools"
        RUNTIME_OUTPUT_DIRECTORY "${CMAKE_BINARY_DIR}/bin")
