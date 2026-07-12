from backend.character_identity import with_kurage_character


def test_character_identity_is_added_once():
    prompt = with_kurage_character("vertical market analysis studio")
    assert "short silver-white bob haircut" in prompt
    assert prompt.count("recurring original Kurage heroine") == 1
    assert with_kurage_character(prompt).count("recurring original Kurage heroine") == 1


def test_explicit_no_character_is_preserved():
    assert with_kurage_character("no character, empty landscape") == "no character, empty landscape"
