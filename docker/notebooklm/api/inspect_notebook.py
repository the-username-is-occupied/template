import asyncio
import json
import pprint

from pool import get_client

ACCOUNT_ID = "019e9bec-8a95-7008-9847-94223e72c57b"
NOTEBOOK_ID = "3bc8c2c8-c322-488c-99b3-48f39eb22782"


def make_jsonable(obj):
    # Try common conversions for pydantic/dataclass-like objects
    try:
        if hasattr(obj, "dict"):
            return obj.dict()
        if hasattr(obj, "__dict__"):
            return obj.__dict__
        return obj
    except Exception:
        return str(obj)


async def main():
    client = get_client(ACCOUNT_ID)
    try:
        desc = await client.notebooks.get_description(NOTEBOOK_ID)
        meta = await client.notebooks.get_metadata(NOTEBOOK_ID)

        print("--- DESCRIPTION (repr) ---")
        pprint.pprint(desc)
        print()
        print("--- DESCRIPTION (json) ---")
        print(json.dumps(make_jsonable(desc), default=str, indent=2, ensure_ascii=False))
        print()

        print("--- METADATA (repr) ---")
        pprint.pprint(meta)
        print()
        print("--- METADATA (json) ---")
        print(json.dumps(make_jsonable(meta), default=str, indent=2, ensure_ascii=False))

    finally:
        try:
            await client.close()
        except Exception:
            pass


if __name__ == "__main__":
    asyncio.run(main())
