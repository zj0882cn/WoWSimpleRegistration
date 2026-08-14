/*
 * Debug test: 使用 AzerothCore SRP6 库验证客户端计算
 * 编译: cd build && make client_srp6_test
 */
#include "BigNumber.h"
#include "CryptoHash.h"
#include "CryptoRandom.h"
#include "SRP6.h"
#include "Util.h"
#include <iostream>
#include <cstring>

using namespace Acore::Crypto;
using SHA1 = Acore::Crypto::SHA1;

static uint8 const BOT_SALT[] = {
    0x00,0x00,0x00,0x00,0x00,0x00,0x00,0x00,0x00,0x00,0x00,0x00,0x00,0x00,0x00,0x00,
    0x00,0x00,0x00,0x00,0x00,0x00,0x00,0x00,0x00,0x00,0x00,0x00,0x00,0x00,0x00,0x00
};

static void hexDump(char const* label, uint8 const* data, size_t len) {
    std::cout << label << " [" << len << "]: ";
    for (size_t i=0; i<std::min(len,size_t(32)); ++i)
        printf("%02x", data[i]);
    if (len>32) std::cout << "...";
    std::cout << std::endl;
}

int main() {
    std::string username = "BOT001";
    std::string password = "27DE305349D21B5752FA79BD2C98518C012F4C4B1314CD4D";

    std::cout << "=== Test 1: inner = SHA1(username + ':' + password) ===" << std::endl;
    auto inner = SHA1::GetDigestOf(username, ":", password);
    hexDump("inner", inner.data(), inner.size());

    std::cout << "\n=== Test 2: x_hash = SHA1(salt || inner) ===" << std::endl;
    SRP6::Salt salt{};
    memcpy(salt.data(), BOT_SALT, 32);
    auto xHash = SHA1::GetDigestOf(salt, inner);
    hexDump("x_hash", xHash.data(), xHash.size());

    std::cout << "\n=== Test 3: Compare CalculateVerifier ===" << std::endl;
    auto v_ref = SRP6::CalculateVerifier(username, password, salt);
    hexDump("v_ref (SRP6::CalculateVerifier)", v_ref.data(), v_ref.size());

    // 手动计算 v = g^x mod N
    BigNumber x(xHash);
    BigNumber const g(SRP6::g);
    BigNumber const N(SRP6::N);
    auto v_manual = g.ModExp(x, N).ToByteArray<32>();
    hexDump("v_manual (g^x mod N)", v_manual.data(), v_manual.size());

    std::cout << "\nv_ref == v_manual? " << (v_ref == v_manual ? "YES" : "NO") << std::endl;

    std::cout << "\n=== Test 4: SessionKey computation ===" << std::endl;
    auto a = Acore::Crypto::GetRandomBytes<32>();
    BigNumber aVal(a);
    auto A = g.ModExp(aVal, N).ToByteArray<32>();
    hexDump("A = g^a mod N", A.data(), A.size());

    // B = 3*v + g^b mod N
    auto b = Acore::Crypto::GetRandomBytes<32>();
    BigNumber bVal(b);
    BigNumber vBn(v_ref);
    auto serverB = ((g.ModExp(bVal, N) + (vBn * 3)) % N).ToByteArray<32>();
    hexDump("B = g^b + 3v mod N", serverB.data(), serverB.size());

    // Client computes S = (B - 3v)^(a + ux) mod N  
    auto u = SHA1::GetDigestOf(A, serverB);
    hexDump("u = SHA1(A||B)", u.data(), u.size());
    BigNumber uBn(u);

    auto exponent = aVal + (uBn * x);
    auto base = ((BigNumber(serverB) - (vBn * 3)) % N);
    base = (base + N) % N;
    auto S_client = base.ModExp(exponent, N).ToByteArray<32>();
    hexDump("S_client", S_client.data(), S_client.size());

    // Server computes S = (A * v^u)^b mod N
    auto S_server = (BigNumber(A) * vBn.ModExp(uBn, N)).ModExp(bVal, N).ToByteArray<32>();
    hexDump("S_server", S_server.data(), S_server.size());

    std::cout << "S_client == S_server? " << (S_client == S_server ? "YES" : "NO") << std::endl;

    // Session key
    auto K = SRP6::SHA1Interleave(S_client);
    hexDump("SessionKey K", K.data(), K.size());

    // M1 = SHA1(NgHash, userHash, salt, A, B, K)
    auto userHash = SHA1::GetDigestOf(username);
    auto nHash = SHA1::GetDigestOf(SRP6::N);
    auto gHash = SHA1::GetDigestOf(SRP6::g);
    std::array<uint8, 20> ngHash{};
    std::transform(nHash.begin(), nHash.end(), gHash.begin(), ngHash.begin(), std::bit_xor<>{});
    hexDump("NgHash", ngHash.data(), ngHash.size());
    hexDump("userHash=I", userHash.data(), userHash.size());

    auto M1 = SHA1::GetDigestOf(ngHash, userHash, salt, A, serverB, K);
    hexDump("M1", M1.data(), M1.size());

    std::cout << "\n=== All tests complete ===" << std::endl;
    return 0;
}
