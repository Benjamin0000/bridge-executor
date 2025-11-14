// SPDX-License-Identifier: MIT
pragma solidity ^0.8.20;

interface IERC20 {
    function transferFrom(address from, address to, uint256 amount) external returns (bool);
    function transfer(address to, uint256 amount) external returns (bool);
}

contract Bridge {
    /* ========== EVENTS ========== */
 
    event BridgeDeposit(
        string indexed nonce,
        address indexed from,
        address indexed tokenFrom,
        uint256 amount,
        address to,
        address tokenTo,
        address poolAddress, 
        uint64 desChain
    );

    event PoolAddressUpdated(
        address indexed oldPool,
        address indexed newPool
    );

    struct Deposit {
        bool status;        // true if deposit recorded
        address tokenFrom;  // token address deposited (address(0) for native ETH)
        address tokenTo;    // token expected on destination chain
        uint256 amount;     // deposited amount
        address to;         // destination address (on target chain)
        address depositor;  // who deposited
        address pool;       // pool address that received the funds
        uint256 timestamp;  // when deposit happened
        uint64 desChain; 
    }

    mapping(string => Deposit) public deposits;
    address public owner;
    address public poolAddress;
    uint256 private _locked = 1;

    modifier nonReentrant() {
        require(_locked == 1, "Reentrant call");
        _locked = 2;
        _;
        _locked = 1;
    }

    modifier onlyOwner() {
        require(msg.sender == owner, "Only owner");
        _;
    }

    constructor(address _poolAddress) {
        require(_poolAddress != address(0), "invalid pool");
        owner = msg.sender;
        poolAddress = _poolAddress;
    }

    /// @notice Deposit native ETH or ERC20 tokens and forward to pool address.
    function bridgeDeposit(
        address tokenFrom,
        address tokenTo,
        address to,
        uint256 amount,
        string calldata nonce, 
        uint64 desChain
    ) external payable nonReentrant {
        require(bytes(nonce).length > 0, "nonce required");
        require(to != address(0), "invalid to address");
        require(poolAddress != address(0), "pool not set");
        require(!deposits[nonce].status, "nonce already used");

        if (tokenFrom == address(0)) {
            // Native ETH deposit
            require(msg.value == amount, "msg.value must equal amount for ETH");
            (bool sent, ) = poolAddress.call{value: amount}("");
            require(sent, "ETH transfer to pool failed");
        } else {
            // ERC20 deposit
            require(msg.value == 0, "Do not send ETH when depositing ERC20");
            bool ok = IERC20(tokenFrom).transferFrom(msg.sender, poolAddress, amount);
            require(ok, "ERC20 transfer to pool failed");
        }

        deposits[nonce] = Deposit({
            status: true,
            tokenFrom: tokenFrom,
            tokenTo: tokenTo,
            amount: amount,
            to: to,
            depositor: msg.sender,
            pool: poolAddress,
            timestamp: block.timestamp, 
            desChain: desChain
        });
        emit BridgeDeposit(nonce, msg.sender, tokenFrom, amount, to, tokenTo, poolAddress, desChain);
    }

    /* ========== ADMIN UTILITIES ========== */
    function setOwner(address newOwner) external onlyOwner {
        require(newOwner != address(0), "zero owner");
        owner = newOwner;
    }

    /// @notice Change pool address (where all incoming funds are sent)
    function setPoolAddress(address newPool) external onlyOwner {
        require(newPool != address(0), "invalid pool");
        address oldPool = poolAddress;
        poolAddress = newPool;
        emit PoolAddressUpdated(oldPool, newPool);
    }

    function isActive(string calldata nonce) external view returns (bool) {
        return deposits[nonce].status;
    }

    receive() external payable {
        revert("Use bridgeDeposit for ETH deposits");
    }
}
 